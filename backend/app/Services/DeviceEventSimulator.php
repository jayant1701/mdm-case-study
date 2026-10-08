<?php

namespace App\Services;

use App\Contracts\DeviceProvider;
use App\Contracts\Persistence\EventStateRepository;
use App\Contracts\Persistence\TransactionBoundary;
use App\Data\DeviceEvent;
use App\Data\DeviceSnapshot;
use Illuminate\Support\Str;
use RuntimeException;

final class DeviceEventSimulator
{
    public function __construct(private DeviceProvider $provider, private EventStateRepository $states, private EventReceiver $receiver, private TransactionBoundary $transactions) {}

    public function simulate(): array
    {
        $result = $this->transactions->withProviderLock('jamf', fn () => $this->capture($this->provider->snapshot(), false));
        foreach ($result['events'] as $event) {
            $this->receiver->dispatch($event);
        }

        return ['emitted' => count($result['events']), 'producer_generation' => $result['generation'], 'event_ids' => array_map(fn ($event) => $event->event_id, $result['events'])];
    }

    /** Caller owns provider lock. Full sync silently fences baseline and consumer versions. */
    public function capture(DeviceSnapshot $snapshot, bool $fence): array
    {
        $previous = $this->states->producerStates('jamf');
        $current = [];
        $protected = [];
        foreach ($snapshot->devices as $record) {
            if (! $record->providerId || ! $record->serial) {
                if (! $fence) {
                    throw new RuntimeException('Simulation requires provider ID and serial for every source device.');
                } if ($record->providerId) {
                    $protected[$record->providerId] = true;
                } else {
                    foreach ($previous as $known) {
                        if ($known->serial_code === $record->serial) {
                            $protected[$known->provider_device_id] = true;
                        }
                    }
                }

continue;
            }
            $current[$record->providerId] = DeviceEvent::fromRecord($record);
        }
        $plans = [];
        foreach ($current as $id => $device) {
            $old = $previous->get($id);
            $state = $device['email'] === null ? 'unassigned' : 'assigned';
            $hash = DeviceEvent::semanticHash($device, $state);
            $changed = ! $old || $old->state !== $state || $old->fingerprint !== $hash;
            if ($changed || $fence) {
                $plans[] = ['id' => (string) $id, 'device' => $device, 'state' => $state, 'hash' => $hash, 'old' => $old, 'changed' => $changed];
            }
        }
        foreach ($previous as $id => $old) {
            if (! isset($current[$id]) && ! isset($protected[$id]) && ($old->state !== 'removed' || $fence)) {
                $plans[] = ['id' => (string) $id, 'device' => $old->device, 'state' => 'removed', 'hash' => DeviceEvent::semanticHash($old->device, 'removed'), 'old' => $old, 'changed' => $old->state !== 'removed'];
            }
        }
        if (! $plans) {
            return ['events' => [], 'generation' => null];
        }
        $generation = $this->states->nextGeneration('jamf');
        $events = [];
        foreach ($plans as $plan) {
            $id = $plan['id'];
            $old = $plan['old'];
            $consumer = $this->states->consumerState('jamf', $id);
            if (($old && $old->serial_code !== $plan['device']['serial_code']) || ($consumer && $consumer->serial_code !== $plan['device']['serial_code'])) {
                throw new RuntimeException('Provider identity cannot change its serial in the simulator.');
            }
            $version = $plan['changed'] ? max($old?->version ?? 0, $consumer?->version ?? 0) + 1 : ($old?->version ?? 1);
            $type = $plan['state'] === 'removed' ? 'device.removed' : ($plan['state'] === 'unassigned' ? 'device.unassigned' : (! $old || $old->state === 'removed' ? 'device.added' : 'device.changed'));
            $event = DeviceEvent::parse(['event_id' => (string) Str::uuid(), 'provider_device_id' => $id, 'version' => $version, 'producer_generation' => $generation, 'type' => $type, 'device' => $plan['device']]);
            if ($fence && $consumer) {
                if ($consumer->fingerprint !== $event->revisionHash() && $consumer->version >= $version) {
                    $version = $consumer->version + 1;
                } else {
                    $version = max($version, $consumer->version);
                }
                $payload = $event->envelope;
                $payload['version'] = $version;
                $event = DeviceEvent::parse($payload);
            }
            $data = ['version' => $version, 'producer_generation' => $generation, 'state' => $plan['state'], 'serial_code' => $plan['device']['serial_code'], 'fingerprint' => $plan['hash'], 'device' => $plan['device']];
            $this->states->storeProducer('jamf', $id, $data);
            if ($fence) {
                $data['fingerprint'] = $event->revisionHash();
                $this->states->storeConsumer('jamf', $id, $data);
            } else {
                $events[] = $this->receiver->persist($event);
            }
        }

        return ['events' => $events, 'generation' => $generation];
    }
}
