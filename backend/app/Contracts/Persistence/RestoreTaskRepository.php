<?php

namespace App\Contracts\Persistence;

use App\Models\Asset;
use Illuminate\Support\Collection;

interface RestoreTaskRepository
{
    public function recordLocalDeletion(Asset $asset): void;

    public function pendingFor(string $provider): Collection;

    public function recordAttempt(int $id): void;

    public function resolve(int $id, string $status): void;

    public function markRestoredBySerial(string $provider, string $serial): void;
}
