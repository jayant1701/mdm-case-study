<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Persistence\AssetRepository;
use App\Data\AssetWriteResult;
use App\Models\Asset;
use App\Models\AssetAttribute;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use RuntimeException;

final class EloquentAssetRepository implements AssetRepository
{
    public function findBySerial(string $serial): ?Asset
    {
        return Asset::where('serial_code', $serial)->first();
    }

    public function providerIdentityUsesDifferentSerial(string $provider, string $providerId, string $serial): bool
    {
        return Asset::where('provider', $provider)->where('provider_device_id', $providerId)->where('serial_code', '!=', $serial)->exists();
    }

    public function persistSnapshot(string $serial, array $fields, array $specifications): AssetWriteResult
    {
        $asset = Asset::firstOrNew(['serial_code' => $serial]);
        $created = ! $asset->exists;
        if ($asset->exists && isset($fields['report_date']) && $fields['report_date'] !== null && $asset->report_date !== null && CarbonImmutable::parse($fields['report_date'])->lt($asset->report_date)) {
            throw new RuntimeException('Older reportDate cannot overwrite newer asset state: '.$serial);
        }
        $asset->fill($fields);
        $changed = $asset->isDirty();
        $asset->save();
        $attributes = AssetAttribute::firstOrNew(['asset_id' => $asset->id]);
        $attributes->fill($specifications);
        $changed = $changed || ! $attributes->exists || $attributes->isDirty();
        $attributes->save();

        return new AssetWriteResult($created, $changed);
    }

    public function removeBySerial(string $provider, string $serial): int
    {
        return Asset::where('provider', $provider)->where('serial_code', $serial)->delete();
    }

    public function removeProviderDevice(string $provider, string $providerId): int
    {
        return Asset::where('provider', $provider)->where('provider_device_id', $providerId)->delete();
    }

    public function removeOutsideSnapshot(string $provider, array $serials, array $protectedProviderIds): int
    {
        $query = Asset::where('provider', $provider)->whereNotIn('serial_code', $serials);
        if ($protectedProviderIds) {
            $query->where(fn ($q) => $q->whereNull('provider_device_id')->orWhereNotIn('provider_device_id', $protectedProviderIds));
        }

        return $query->delete();
    }

    public function requireForDeletion(int $id): Asset
    {
        return Asset::findOrFail($id);
    }

    public function deleteLocal(int $id): void
    {
        Asset::findOrFail($id)->delete();
    }

    public function paginateWithRelations(int $perPage): LengthAwarePaginator
    {
        return Asset::with(['employee', 'deletedEmployee', 'deviceModel', 'specifications'])->orderBy('id')->paginate($perPage);
    }

    public function details(int $id): Asset
    {
        return Asset::with(['employee', 'deletedEmployee', 'deviceModel', 'specifications'])->findOrFail($id);
    }
}
