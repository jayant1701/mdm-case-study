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
        return ['pending' => $this->pendingCount()];
    }
}
