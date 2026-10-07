<?php

namespace App\Services;

use App\Contracts\DeviceProvider;
use App\Models\Employee;
use App\Models\RestoreTask;
use App\Models\SyncRun;
use Illuminate\Support\Facades\DB;

final class JamfSyncService
{
    public function __construct(private DeviceProvider $provider, private DeviceReconciler $reconciler) {}

    public function full(): SyncRun
    {
        return $this->run('full', function (array &$counts) {
            $snapshot = $this->provider->snapshot();
            $counts['warnings'] = $snapshot->warnings;
            $this->reconciler->validateIdentities($snapshot->devices);
            foreach ($snapshot->devices as $d) {
                $this->reconciler->apply($d, $counts);
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
            // Read once: the mock has no true targeted HTTP endpoint.
            $snapshot = $this->provider->snapshot();
            $this->reconciler->validateIdentities($snapshot->devices);
            foreach (RestoreTask::where('status', 'pending')->where('provider', 'jamf')->get() as $task) {
                $task->increment('attempts');
                $device = null;
                foreach ($snapshot->devices as $candidate) {
                    if (($task->provider_device_id && $candidate->providerId === $task->provider_device_id) || (! $task->provider_device_id && $candidate->serial === $task->serial_code)) {
                        $device = $candidate;
                        break;
                    }
                }
                if (! $device) {
                    $task->update(['status' => 'upstream_missing', 'error' => null]);
                } elseif (! $device->email) {
                    $task->update(['status' => 'upstream_unassigned', 'error' => null]);
                } elseif (! $device->serial) {
                    throw new \RuntimeException('Cannot restore a source device without a serial.');
                } else {
                    $this->reconciler->apply($device, $counts);
                    $task->update(['status' => 'restored', 'error' => null]);
                }
            }
        });
    }

    private function run(string $mode, callable $operation): SyncRun
    {
        $run = SyncRun::create(['provider' => 'jamf', 'mode' => $mode, 'status' => 'in_progress', 'started_at' => now()]);
        $counts = ['created_assets' => 0, 'updated_assets' => 0, 'unchanged_assets' => 0, 'removed_assets' => 0, 'skipped_unassigned' => 0, 'skipped_missing_serial' => 0, 'created_employees' => 0, 'warnings' => []];
        try {
            $counts = DB::transaction(function () use ($operation) {
                $counts = ['created_assets' => 0, 'updated_assets' => 0, 'unchanged_assets' => 0, 'removed_assets' => 0, 'skipped_unassigned' => 0, 'skipped_missing_serial' => 0, 'created_employees' => 0, 'warnings' => []];
                DB::table('sync_locks')->where('provider', 'jamf')->lockForUpdate()->first();
                $before = Employee::count();
                $operation($counts);
                $counts['created_employees'] = Employee::count() - $before;

                return $counts;
            }, 3);
            $run->update(['status' => 'succeeded', 'completed_at' => now(), 'counters' => $counts]);
        } catch (\Throwable $e) {
            $run->update(['status' => 'failed', 'completed_at' => now(), 'error_details' => $e->getMessage()]);
            throw $e;
        }

        return $run->fresh();
    }
}
