<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Persistence\ProviderOutboxRepository;
use App\Models\ProducerOutbox;

final class EloquentProviderOutboxRepository implements ProviderOutboxRepository
{
    public function pendingCount(): int
    {
        return ProducerOutbox::where('status', 'pending')->count();
    }

    public function activity(): array
    {
        return ['pending' => $this->pendingCount(), 'data' => ProducerOutbox::latest('id')->limit(50)->get()->map(fn ($row) => [
            'id' => $row->id, 'event_id' => $row->event_id, 'provider_device_id' => $row->provider_device_id,
            'device_name' => $row->payload['device']['asset']['device_name'], 'type' => $row->payload['type'],
            'status' => $row->status, 'attempts' => $row->attempts, 'last_error' => $row->last_error,
            'created_at' => $row->created_at, 'published_at' => $row->published_at,
        ])->all()];
    }
}
