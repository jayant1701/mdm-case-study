<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Persistence\DeletedAssetRepository;
use App\Models\Asset;
use App\Models\DeletedAsset;
use Illuminate\Support\Collection;
use RuntimeException;

final class EloquentDeletedAssetRepository implements DeletedAssetRepository
{
    public function recordLocalDeletion(Asset $asset): void
    {
        if (! $asset->provider_device_id) {
            throw new RuntimeException('Local deletion requires a provider device ID for later reconciliation.');
        }
        DeletedAsset::updateOrCreate(
            ['provider' => $asset->provider, 'serial_code' => $asset->serial_code],
            ['provider_device_id' => $asset->provider_device_id, 'device_name' => $asset->device_name, 'deleted_at' => now()],
        );
    }

    public function recoverableFor(string $provider): Collection
    {
        return DeletedAsset::where('provider', $provider)->orderBy('id')->get();
    }

    public function deletedFor(string $provider): array
    {
        return DeletedAsset::where('provider', $provider)
            ->latest('deleted_at')
            ->get()
            ->map(fn (DeletedAsset $asset) => [
                'provider' => $asset->provider,
                'provider_device_id' => $asset->provider_device_id,
                'serial_code' => $asset->serial_code,
                'device_name' => $asset->device_name,
                'deleted_at' => $asset->deleted_at?->toISOString(),
            ])->all();
    }

    public function remove(int $id): void
    {
        DeletedAsset::whereKey($id)->delete();
    }

    public function removeByIdentity(string $provider, ?string $providerId, string $serial): void
    {
        DeletedAsset::where('provider', $provider)
            ->where(fn ($query) => $query->where('serial_code', $serial)->when($providerId, fn ($q) => $q->orWhere('provider_device_id', $providerId)))
            ->delete();
    }
}
