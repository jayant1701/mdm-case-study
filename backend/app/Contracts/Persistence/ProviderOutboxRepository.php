<?php

namespace App\Contracts\Persistence;

interface ProviderOutboxRepository
{
    public function activity(): array;

    public function pendingCount(): int;
}
