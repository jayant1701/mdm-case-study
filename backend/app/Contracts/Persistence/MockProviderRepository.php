<?php

namespace App\Contracts\Persistence;

use App\Models\MockProviderDevice;
use Illuminate\Support\Collection;

interface MockProviderRepository
{
    public function find(string $id): ?MockProviderDevice;

    public function active(): Collection;

    public function hasAny(): bool;

    public function store(string $id, array $device, string $state): void;

    public function markRemovedExcept(array $ids): void;

    public function serialUsedByOther(string $serial, string $id): bool;
}
