<?php

namespace App\Contracts\Persistence;

use App\Data\AssetWriteResult;
use App\Models\Asset;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface AssetRepository
{
    public function findBySerial(string $serial): ?Asset;

    public function providerIdentityUsesDifferentSerial(string $provider, string $providerId, string $serial): bool;

    /** Persist the asset aggregate, including its specifications, inside the caller's transaction. */
    public function persistSnapshot(string $serial, array $fields, array $specifications): AssetWriteResult;

    public function removeBySerial(string $provider, string $serial): int;

    public function removeProviderDevice(string $provider, string $providerId): int;

    public function removeOutsideSnapshot(string $provider, array $serials, array $protectedProviderIds): int;

    public function requireForDeletion(int $id): Asset;

    public function deleteLocal(int $id): void;

    public function paginateWithRelations(int $perPage): LengthAwarePaginator;

    public function details(int $id): Asset;
}
