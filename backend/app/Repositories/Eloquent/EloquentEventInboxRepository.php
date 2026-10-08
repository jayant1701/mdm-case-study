<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Persistence\EventInboxRepository;
use App\Data\DeviceEvent;
use App\Models\EventInbox;
use Illuminate\Support\Collection;

final class EloquentEventInboxRepository implements EventInboxRepository
{
    public function findByEventId(string $eventId): ?EventInbox
    {
        return EventInbox::where('event_id', $eventId)->first();
    }

    public function findDeviceVersion(string $provider, string $providerId, int $version): ?EventInbox
    {
        return EventInbox::where('provider', $provider)->where('provider_device_id', $providerId)->where('version', $version)->first();
    }

    public function recordNotification(string $provider, array $payload): EventInbox
    {
        return EventInbox::create(['event_id' => $payload['event_id'], 'provider' => $provider, 'provider_device_id' => $payload['provider_device_id'], 'type' => $payload['type'], 'payload' => $payload, 'status' => 'pending', 'version' => $payload['version'], 'producer_generation' => $payload['producer_generation'], 'content_hash' => DeviceEvent::parse($payload)->contentHash()]);
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

    public function markSucceeded(int $id, string $outcome = 'applied'): void
    {
        $this->require($id)->update(['status' => 'succeeded', 'outcome' => $outcome, 'processed_at' => now(), 'last_error' => null]);
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
