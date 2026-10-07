<?php

namespace App\Jobs;

use App\Models\EventInbox;
use App\Models\SyncRun;
use App\Services\SyncCoordinator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

final class ProcessDeviceEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $inboxId) {}

    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function handle(SyncCoordinator $sync): void
    {
        $processed = false;
        try {
            DB::transaction(function () use ($sync, &$processed) {
                // Same lock order as intake, deletion and full sync; transaction owns lock lifetime.
                DB::table('sync_locks')->where('provider', 'jamf')->lockForUpdate()->first();
                $inbox = EventInbox::findOrFail($this->inboxId);
                if ($inbox->status === 'succeeded') {
                    return;
                }
                $processed = true;
                $inbox->update(['status' => 'processing', 'attempts' => $inbox->attempts + 1]);
                // Both changed and removed notifications fetch current state: stale notifications are safe.
                $sync->device($inbox->provider_device_id);
                $inbox->update(['status' => 'succeeded', 'processed_at' => now(), 'last_error' => null]);
            });
        } catch (\Throwable $e) {
            if ($processed) {
                // Reconciliation rolled back; persist diagnostics outside that transaction.
                DB::transaction(function () use ($e) {
                    DB::table('sync_locks')->where('provider', 'jamf')->lockForUpdate()->first();
                    $inbox = EventInbox::find($this->inboxId);
                    if ($inbox && $inbox->status !== 'succeeded') {
                        $inbox->increment('attempts');
                        $inbox->update(['status' => 'failed', 'last_error' => $e->getMessage()]);
                        SyncRun::create(['provider' => 'jamf', 'mode' => 'event', 'status' => 'failed', 'started_at' => now(), 'completed_at' => now(), 'error_details' => $e->getMessage()]);
                    }
                });
            }
            throw $e;
        }
    }
}
