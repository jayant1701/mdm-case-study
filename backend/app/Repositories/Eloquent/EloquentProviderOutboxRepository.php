<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Persistence\ProviderOutboxRepository;
use App\Models\ProducerOutbox;

final class EloquentProviderOutboxRepository implements ProviderOutboxRepository
{
    private const ACTIVITY_LIMIT = 100;

    public function pendingCount(): int
    {
        return ProducerOutbox::where('status', 'pending')->count();
    }

    public function activity(): array
    {
        $pending = $this->pendingCount();
        $notifications = ProducerOutbox::where('status', 'pending')
            ->orderBy('id')
            ->limit(self::ACTIVITY_LIMIT)
            ->get()
            ->map(function (ProducerOutbox $row) {
                $payload = $row->payload;

                return [
                    'event_id' => $row->event_id,
                    'type' => $payload['type'] ?? null,
                    'provider_device_id' => $row->provider_device_id,
                    'serial_code' => data_get($payload, 'device.serial_code'),
                    'device_name' => data_get($payload, 'device.asset.device_name'),
                    'attempts' => $row->attempts,
                    'last_error' => $row->last_error,
                    'next_attempt_at' => $row->next_attempt_at?->toISOString(),
                    'created_at' => $row->created_at?->toISOString(),
                ];
            })
            ->all();

        return [
            'pending' => $pending,
            'notifications' => $notifications,
            'truncated' => $pending > count($notifications),
        ];
    }
}
