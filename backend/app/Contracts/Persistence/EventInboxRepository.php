<?php

namespace App\Contracts\Persistence;

use App\Models\EventInbox;
use Illuminate\Support\Collection;

interface EventInboxRepository
{
    public function findByEventId(string $eventId): ?EventInbox;

    public function findDeviceVersion(string $provider, string $providerId, int $version): ?EventInbox;

    public function recordNotification(string $provider, array $payload): EventInbox;

    public function require(int $id): EventInbox;

    public function find(int $id): ?EventInbox;

    public function pending(int $limit): Collection;

    public function recent(int $limit): Collection;

    public function failed(int $limit): Collection;

    public function markProcessing(int $id): void;

    public function markSucceeded(int $id, string $outcome = 'applied'): void;

    /** Returns false if another worker already completed this notification. */
    public function markRetrying(int $id, string $error): bool;

    public function markFailed(int $id, string $error): void;

    public function markPending(int $id): EventInbox;
}
