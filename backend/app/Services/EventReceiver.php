<?php

namespace App\Services;

use App\Contracts\Persistence\EventInboxRepository;
use App\Contracts\Persistence\TransactionBoundary;
use App\Jobs\ProcessDeviceEvent;
use App\Models\EventInbox;
use Illuminate\Support\Facades\Log;

final class EventReceiver
{
    public function __construct(private EventInboxRepository $events, private TransactionBoundary $transactions) {}

    public function accept(array $payload): EventInbox
    {
        $inbox = $this->transactions->withProviderLock('jamf', function () use ($payload) {
            $existing = $this->events->findByEventId($payload['event_id']);
            if ($existing) {
                if ($existing->provider_device_id !== $payload['provider_device_id'] || $existing->type !== $payload['type']) {
                    abort(409, 'Event ID was already used for different content.');
                }

                return $existing;
            }

            return $this->events->recordNotification('jamf', $payload);
        });
        if ($inbox->status === 'pending') {
            $this->dispatch($inbox);
        }

        return $inbox;
    }

    public function dispatch(EventInbox $inbox): void
    {
        try {
            ProcessDeviceEvent::dispatch($inbox->id);
        } catch (\Throwable $error) {
            Log::warning('Event dispatch deferred; durable inbox recovery will retry.', ['event_id' => $inbox->event_id, 'error' => $error->getMessage()]);
        }
    }

    public function recover(): int
    {
        $pending = $this->events->pending(100);
        foreach ($pending as $inbox) {
            $this->dispatch($inbox);
        }

        return $pending->count();
    }

    public function retry(int $id): EventInbox
    {
        $inbox = $this->transactions->withProviderLock('jamf', function () use ($id) {
            $record = $this->events->require($id);

            return $record->status === 'failed' ? $this->events->markPending($id) : $record;
        });
        if ($inbox->status === 'pending') {
            $this->dispatch($inbox);
        }

        return $inbox;
    }
}
