<?php

namespace App\Services;

use App\Contracts\Persistence\EventInboxRepository;
use App\Contracts\Persistence\SyncRunRepository;
use App\Contracts\Persistence\TransactionBoundary;

final class EventProcessor
{
    public function __construct(private JamfSyncService $sync, private EventInboxRepository $events, private SyncRunRepository $runs, private TransactionBoundary $transactions) {}

    public function process(int $inboxId): void
    {
        $processed = false;
        try {
            $this->transactions->withProviderLock('jamf', function () use ($inboxId, &$processed) {
                // Consistent lock order with receipt, deletion and full sync; no TTL expiry.
                $inbox = $this->events->require($inboxId);
                if ($inbox->status === 'succeeded') {
                    return;
                }
                $processed = true;
                $this->events->markProcessing($inboxId);
                // Changed and removed notifications both fetch current state, resisting stale events.
                $this->sync->device($inbox->provider_device_id);
                $this->events->markSucceeded($inboxId);
            });
        } catch (\Throwable $error) {
            if ($processed) {
                // Outer rollback removes nested sync changes; record diagnostics independently.
                $this->transactions->withProviderLock('jamf', function () use ($inboxId, $error) {
                    if ($this->events->markFailedAttempt($inboxId, $error->getMessage())) {
                        $this->runs->recordFailedAttempt('jamf', 'event', $error->getMessage());
                    }
                });
            }
            throw $error;
        }
    }
}
