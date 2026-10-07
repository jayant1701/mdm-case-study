<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Persistence\EventInboxRepository;
use App\Models\EventInbox;
use Illuminate\Support\Collection;

final class EloquentEventInboxRepository implements EventInboxRepository
{
    public function findByEventId(string $eventId): ?EventInbox
    {
        return EventInbox::where('event_id', $eventId)->first();
    }

    public function recordNotification(string $provider, array $payload): EventInbox
    {
        return EventInbox::create(['event_id' => $payload['event_id'], 'provider' => $provider, 'provider_device_id' => $payload['provider_device_id'], 'type' => $payload['type'], 'payload' => $payload, 'status' => 'pending']);
    }

    public function require(int $id): EventInbox
    {
        return EventInbox::findOrFail($id);
    }

    public function find(int $id): ?EventInbox
    {
        return EventInbox::find($id);
    }

    public function pending(int $limit): Collection
    {
        return EventInbox::where('status', 'pending')->orderBy('id')->limit($limit)->get();
    }

    public function recent(int $limit): Collection
    {
        return EventInbox::latest('id')->limit($limit)->get();
    }

    public function markProcessing(int $id): void
    {
        $record = $this->require($id);
        $record->update(['status' => 'processing', 'attempts' => $record->attempts + 1]);
    }

    public function markSucceeded(int $id): void
    {
        $this->require($id)->update(['status' => 'succeeded', 'processed_at' => now(), 'last_error' => null]);
    }

    public function markFailedAttempt(int $id, string $error): bool
    {
        return EventInbox::whereKey($id)->where('status', '!=', 'succeeded')->increment('attempts', 1, ['status' => 'failed', 'last_error' => $error]) > 0;
    }

    public function markPending(int $id): EventInbox
    {
        $record = $this->require($id);
        $record->update(['status' => 'pending', 'last_error' => null]);

        return $record->fresh();
    }
}
