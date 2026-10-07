<?php

namespace App\Services;

use App\Contracts\DeviceProvider;
use App\Contracts\Persistence\EmployeeRepository;
use App\Contracts\Persistence\RestoreTaskRepository;
use App\Contracts\Persistence\SyncRunRepository;
use App\Contracts\Persistence\TransactionBoundary;
use App\Models\SyncRun;

final class JamfSyncService
{
    public function __construct(private DeviceProvider $provider, private DeviceReconciler $reconciler, private EmployeeRepository $employees, private RestoreTaskRepository $restorations, private SyncRunRepository $runs, private TransactionBoundary $transactions) {}

    public function full(): SyncRun
    {
        return $this->run('full', function (array &$counts) {
            $snapshot = $this->provider->snapshot();
            $counts['warnings'] = $snapshot->warnings;
            $this->reconciler->validateIdentities($snapshot->devices);
            foreach ($snapshot->devices as $device) {
                $this->reconciler->apply($device, $counts);
            }
            $this->reconciler->removeAbsent($snapshot->devices, $counts);
        });
    }

    public function device(string $id): SyncRun
    {
        return $this->run('event', function (array &$counts) use ($id) {
            $device = $this->provider->findByProviderId($id);
            if ($device) {
                $this->reconciler->validateIdentities([$device]);
                $this->reconciler->apply($device, $counts);
            } else {
                $this->reconciler->removeProviderDevice($id, $counts);
            }
        });
    }

    public function restore(): SyncRun
    {
        return $this->run('restore', function (array &$counts) {
            // The mock has no targeted HTTP endpoint; read its current complete state once.
            $snapshot = $this->provider->snapshot();
            $this->reconciler->validateIdentities($snapshot->devices);
            foreach ($this->restorations->pendingFor('jamf') as $task) {
                $this->restorations->recordAttempt($task->id);
                $device = null;
                foreach ($snapshot->devices as $candidate) {
                    if (($task->provider_device_id && $candidate->providerId === $task->provider_device_id) || (! $task->provider_device_id && $candidate->serial === $task->serial_code)) {
                        $device = $candidate;
                        break;
                    }
                }
                if (! $device) {
                    $this->restorations->resolve($task->id, 'upstream_missing');
                } elseif (! $device->email) {
                    $this->restorations->resolve($task->id, 'upstream_unassigned');
                } elseif (! $device->serial) {
                    throw new \RuntimeException('Cannot restore a source device without a serial.');
                } else {
                    $this->reconciler->apply($device, $counts);
                    $this->restorations->resolve($task->id, 'restored');
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
