<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Persistence\RestoreTaskRepository;
use App\Models\Asset;
use App\Models\RestoreTask;
use Illuminate\Support\Collection;

final class EloquentRestoreTaskRepository implements RestoreTaskRepository
{
    public function recordLocalDeletion(Asset $asset): void
    {
        RestoreTask::updateOrCreate(['provider' => $asset->provider, 'serial_code' => $asset->serial_code], ['provider_device_id' => $asset->provider_device_id, 'deleted_at' => now(), 'status' => 'pending', 'attempts' => 0, 'error' => null]);
    }

    public function pendingFor(string $provider): Collection
    {
        return RestoreTask::where('status', 'pending')->where('provider', $provider)->get();
    }

    public function recordAttempt(int $id): void
    {
        RestoreTask::findOrFail($id)->increment('attempts');
    }

    public function resolve(int $id, string $status): void
    {
        RestoreTask::findOrFail($id)->update(['status' => $status, 'error' => null]);
    }

    public function markRestoredBySerial(string $provider, string $serial): void
    {
        RestoreTask::where('provider', $provider)->where('serial_code', $serial)->where('status', 'pending')->update(['status' => 'restored', 'error' => null]);
    }
}
