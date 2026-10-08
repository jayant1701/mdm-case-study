<?php

namespace App\Contracts\Persistence;

use App\Models\Asset;
use Illuminate\Support\Collection;

interface DeletedAssetRepository
{
    public function recordLocalDeletion(Asset $asset): void;

    public function recoverableFor(string $provider): Collection;

    public function deletedFor(string $provider): array;

    public function remove(int $id): void;

    public function removeByIdentity(string $provider, ?string $providerId, string $serial): void;
}
