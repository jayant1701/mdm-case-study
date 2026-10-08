<?php

namespace App\Services;

use App\Contracts\DeviceProvider;
use App\Contracts\Persistence\DeletedAssetRepository;
use App\Contracts\Persistence\EmployeeRepository;
use App\Contracts\Persistence\SyncRunRepository;
use App\Contracts\Persistence\TransactionBoundary;
use App\Contracts\TargetedDeviceProvider;
use App\Data\DeviceEvent;
use App\Models\SyncRun;

final class JamfSyncService
{
    public function __construct(private DeviceProvider $provider, private DeviceReconciler $reconciler, private EmployeeRepository $employees, private DeletedAssetRepository $deletedAssets, private SyncRunRepository $runs, private TransactionBoundary $transactions, private DeviceEventSimulator $simulator, private TargetedDeviceProvider $targeted) {}

    public function full(): SyncRun
    {
        return $this->run('full', function (array &$counts) {
            if (config('mdm.provider_mode') === 'remote') {
                app(RemoteProviderSyncService::class)->full($counts);

                return;
            }
            $snapshot = $this->provider->snapshot();
            $counts['warnings'] = $snapshot->warnings;
            $this->reconciler->validateIdentities($snapshot->devices);
            $simulation = $this->simulator->capture($snapshot, true);
            foreach ($simulation['snapshot']->devices as $device) {
                $this->reconciler->apply($device, $counts, $simulation['generation']);
            }
            $this->reconciler->removeAbsent($snapshot->devices, $counts);
        });
    }

    public function applyEvent(DeviceEvent $event): SyncRun
    {
        return $this->run('event', function (array &$counts) use ($event) {
            $this->reconciler->validateIdentities([$event->device]);
            if ($event->state() === 'removed') {
                $this->reconciler->removeProviderDevice($event->device->providerId, $counts);
            } else {
                $this->reconciler->validateIdentities([$event->device]);
                $this->reconciler->apply($event->device, $counts, $event->envelope['producer_generation']);
            }
        });
    }

    public function reconcileProvider(): SyncRun
    {
        return $this->run('provider', function (array &$counts) {
            if (config('mdm.provider_mode') === 'remote') {
                app(RemoteProviderSyncService::class)->reconcile($counts);

                return;
            }
            $snapshot = $this->targeted->snapshot();
            $this->reconciler->validateIdentities($snapshot->devices);
            $fence = $this->simulator->capture($snapshot, true);
            foreach ($fence['snapshot']->devices as $device) {
                $this->reconciler->apply($device, $counts, $fence['generation']);
            }
            $this->reconciler->removeAbsent($fence['snapshot']->devices, $counts);
        });
    }

    public function restore(): SyncRun
    {
        return $this->run('restore', function (array &$counts) {
            if (config('mdm.provider_mode') === 'remote') {
                app(RemoteProviderSyncService::class)->restore($counts);

                return;
            }
            foreach ($this->deletedAssets->recoverableFor('jamf') as $deletedAsset) {
                $event = $this->targeted->current($deletedAsset->provider_device_id);
                if (! $event) {
                    $this->deletedAssets->remove($deletedAsset->id);

                    continue;
                }
                if ($event->device->serial !== $deletedAsset->serial_code) {
                    throw new \RuntimeException('Restoration serial does not match the retained device identity.');
                }
                if ($event->state() === 'removed') {
                    $this->simulator->publish($event->device, 'removed', true);
                    $this->deletedAssets->remove($deletedAsset->id);

                    continue;
                }
                if (! $event->device->email) {
                    $this->simulator->publish($event->device, 'unassigned', true);
                    $this->deletedAssets->remove($deletedAsset->id);

                    continue;
                }
                $this->reconciler->validateIdentities([$event->device]);
                $fence = $this->simulator->publish($event->device, 'assigned', true);
                $this->reconciler->apply($event->device, $counts, $fence['generation']);
                $this->deletedAssets->remove($deletedAsset->id);
            }
            foreach ($this->employees->deletedEmployeeProviderIds('jamf') as $providerId) {
                $event = $this->targeted->current($providerId);
                if ($event && $event->state() !== 'removed' && $event->device->email) {
                    $this->reconciler->apply($event->device, $counts, $event->envelope['producer_generation']);
                }
            }
        });
    }

    private function run(string $mode, callable $operation): SyncRun
    {
        $run = $this->runs->start('jamf', $mode);
        try {
            $counts = $this->transactions->withProviderLock('jamf', function () use ($operation) {
                // Reinitialize counters for each transaction retry, including deadlock recovery.
                $counts = ['created_assets' => 0, 'updated_assets' => 0, 'unchanged_assets' => 0, 'removed_assets' => 0, 'skipped_unassigned' => 0, 'skipped_missing_serial' => 0, 'created_employees' => 0, 'warnings' => []];
                $before = $this->employees->count();
                $operation($counts);
                $counts['created_employees'] = $this->employees->count() - $before;

                return $counts;
            }, 3);

            return $this->runs->succeed($run->id, $counts);
        } catch (\Throwable $error) {
            $this->runs->fail($run->id, $error->getMessage());
            throw $error;
        }
    }
}
