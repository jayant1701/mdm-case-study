<?php

namespace App\Services;

use App\Contracts\DeviceProvider;
use App\Contracts\Persistence\DeletedAssetRepository;
use App\Contracts\Persistence\EmployeeRepository;
use App\Contracts\Persistence\EventStateRepository;
use App\Contracts\RemoteProviderGateway;
use App\Data\DeviceEvent;
use RuntimeException;

final class RemoteProviderSyncService
{
    public function __construct(private RemoteProviderGateway $provider, private DeviceProvider $fixture, private DeviceReconciler $reconciler, private EventStateRepository $states, private DeletedAssetRepository $deletedAssets, private EmployeeRepository $employees) {}

    public function full(array &$counts): void
    {
        // Retain the assignment's direct JSON-read path. The remote provider owns its versions.
        $snapshot = $this->fixture->snapshot();
        $counts['warnings'] = $snapshot->warnings;
        $response = json_decode(file_get_contents(config('mdm.mock_path')), true, 512, JSON_THROW_ON_ERROR);
        $this->applySnapshot($this->provider->import($response), $counts);
    }

    public function reconcile(array &$counts): void
    {
        $delivery = $this->provider->retryOutbox();
        $counts['outbox_published'] = (int) ($delivery['published'] ?? 0);
        $counts['outbox_failed'] = (int) ($delivery['failed'] ?? 0);
        $counts['outbox_pending'] = (int) ($delivery['pending'] ?? 0);
        $this->applySnapshot($this->provider->envelopesSnapshot(), $counts);
    }

    private function applySnapshot(array $snapshot, array &$counts): void
    {
        if (! isset($snapshot['generation'], $snapshot['devices']) || ! is_int($snapshot['generation']) || $snapshot['generation'] < 0 || ! is_array($snapshot['devices']) || ! array_is_list($snapshot['devices'])) {
            throw new RuntimeException('Provider returned an invalid complete snapshot.');
        }
        if ($this->states->maximumConsumerGeneration('jamf') > $snapshot['generation']) {
            throw new RuntimeException('Provider snapshot is older than applied events; retry reconciliation.');
        }
        $events = array_map(fn ($payload) => DeviceEvent::parse($payload), $snapshot['devices']);
        $ids = [];
        $serials = [];
        foreach ($events as $event) {
            if (isset($ids[$event->device->providerId]) || isset($serials[$event->device->serial]) || $event->envelope['producer_generation'] > $snapshot['generation']) {
                throw new RuntimeException('Provider snapshot has conflicting identities or ordering.');
            }
            $ids[$event->device->providerId] = true;
            $serials[$event->device->serial] = true;
        }
        $active = array_values(array_filter($events, fn ($event) => $event->state() !== 'removed'));
        $records = array_map(fn ($event) => $event->device, $active);
        $this->reconciler->validateIdentities($records);
        foreach ($events as $event) {
            $this->apply($event, $counts);
        }
        $this->reconciler->removeAbsent($records, $counts);
    }

    public function restore(array &$counts): void
    {
        foreach ($this->deletedAssets->recoverableFor('jamf') as $deletedAsset) {
            $event = $this->provider->current($deletedAsset->provider_device_id);
            if (! $event) {
                $this->deletedAssets->remove($deletedAsset->id);

                continue;
            }
            if ($event->device->serial !== $deletedAsset->serial_code) {
                throw new RuntimeException('Restoration serial does not match retained identity.');
            }
            $this->apply($event, $counts);
            $this->deletedAssets->remove($deletedAsset->id);
        }
        foreach ($this->employees->deletedEmployeeProviderIds('jamf') as $providerId) {
            $event = $this->provider->current($providerId);
            if ($event && $event->state() !== 'removed' && $event->device->email) {
                $this->apply($event, $counts);
            }
        }
    }

    private function apply(DeviceEvent $event, array &$counts): void
    {
        $previous = $this->states->consumerState('jamf', $event->device->providerId);
        if ($previous && ($event->envelope['version'] < $previous->version || $event->envelope['producer_generation'] < $previous->producer_generation)) {
            throw new RuntimeException('Provider lookup is older than applied state; retry recovery.');
        }
        if ($previous && ($previous->serial_code !== $event->device->serial || ($event->envelope['version'] === $previous->version && DeviceEvent::semanticHash($previous->device, $previous->state) !== DeviceEvent::semanticHash($event->envelope['device'], $event->state())))) {
            throw new RuntimeException('Provider lookup conflicts with an applied device version.');
        }
        $this->reconciler->validateIdentities([$event->device]);
        if ($event->state() === 'removed') {
            $this->reconciler->removeProviderDevice($event->device->providerId, $counts);
        } else {
            $this->reconciler->apply($event->device, $counts, $event->envelope['producer_generation']);
        }
        $this->states->storeConsumer('jamf', $event->device->providerId, [
            'version' => $event->envelope['version'], 'producer_generation' => $event->envelope['producer_generation'],
            'state' => $event->state(), 'serial_code' => $event->device->serial,
            'fingerprint' => $event->revisionHash(), 'device' => $event->envelope['device'],
        ]);
    }
}
