<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Employee;
use App\Models\SyncRun;
use App\Services\AssetDeletionService;
use App\Services\JamfSyncService;

final class InventoryController extends Controller
{
    public function assets()
    {
        return Asset::with(['employee', 'deviceModel', 'specifications'])->orderBy('id')->paginate(25);
    }

    public function asset(Asset $asset)
    {
        return $asset->load(['employee', 'deviceModel', 'specifications']);
    }

    public function employees()
    {
        return ['data' => Employee::with('assets')->orderBy('email')->get()];
    }

    public function runs()
    {
        return ['data' => SyncRun::latest('id')->limit(25)->get()];
    }

    public function sync(JamfSyncService $sync)
    {
        return $sync->full();
    }

    public function restore(JamfSyncService $sync)
    {
        return $sync->restore();
    }

    public function deleteAsset(Asset $asset, AssetDeletionService $deletion)
    {
        $deletion->delete($asset);

        return response()->noContent();
    }

    public function deleteEmployee(Employee $employee, AssetDeletionService $deletion)
    {
        $deletion->deleteEmployee($employee);

        return response()->noContent();
    }
}
