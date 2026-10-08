<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Persistence\MockProviderRepository;
use App\Models\MockProviderDevice;
use Illuminate\Support\Collection;

final class EloquentMockProviderRepository implements MockProviderRepository
{
    public function find(string $id): ?MockProviderDevice
    {
        return MockProviderDevice::where('provider', 'jamf')->where('provider_device_id', $id)->first();
    }

    public function active(): Collection
    {
        return MockProviderDevice::where('provider', 'jamf')->where('state', '!=', 'removed')->orderBy('provider_device_id')->get();
    }

    public function hasAny(): bool
    {
        return MockProviderDevice::where('provider', 'jamf')->exists();
    }

    public function store(string $id, array $device, string $state): void
    {
        MockProviderDevice::updateOrCreate(['provider' => 'jamf', 'provider_device_id' => $id], ['serial_code' => $device['serial_code'], 'device' => $device, 'state' => $state]);
    }

    public function markRemovedExcept(array $ids): void
    {
        MockProviderDevice::where('provider', 'jamf')->whereNotIn('provider_device_id', $ids)->update(['state' => 'removed']);
    }

    public function serialUsedByOther(string $serial, string $id): bool
    {
        return MockProviderDevice::where('provider', 'jamf')->where('serial_code', $serial)->where('provider_device_id', '!=', $id)->exists();
    }
}
