<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Employee;
use App\Models\RestoreTask;
use Illuminate\Support\Facades\DB;

final class AssetDeletionService
{
    public function delete(Asset $asset): void
    {
        DB::transaction(function () use ($asset) {
            DB::table('sync_locks')->where('provider', 'jamf')->lockForUpdate()->first();
            $asset = Asset::findOrFail($asset->id);
            RestoreTask::updateOrCreate(['provider' => $asset->provider, 'serial_code' => $asset->serial_code], ['provider_device_id' => $asset->provider_device_id, 'deleted_at' => now(), 'status' => 'pending', 'attempts' => 0, 'error' => null]);
            $asset->delete();
        });
    }

    public function deleteEmployee(Employee $employee): void
    {
        DB::transaction(function () use ($employee) {
            DB::table('sync_locks')->where('provider', 'jamf')->lockForUpdate()->first();
            Employee::findOrFail($employee->id)->delete();
        });
    }
}
