<?php

namespace App\Http\Controllers;

use App\Contracts\Persistence\AssetRepository;
use App\Contracts\Persistence\EmployeeRepository;
use App\Contracts\Persistence\SyncRunRepository;
use App\Services\AssetDeletionService;
use App\Services\JamfSyncService;

final class InventoryController extends Controller
{
    public function __construct(private AssetRepository $assets, private EmployeeRepository $employees, private SyncRunRepository $runs) {}

    public function assets()
    {
        return $this->assets->paginateWithRelations(25);
    }

    public function asset(int $asset)
    {
        return $this->assets->details($asset);
    }

    public function employees()
    {
        return ['data' => $this->employees->listWithAssets()];
    }

    public function runs()
    {
        return ['data' => $this->runs->recent(25)];
    }

    public function sync(JamfSyncService $sync)
    {
        return $sync->full();
    }

    public function syncProvider(JamfSyncService $sync)
    {
        return $sync->reconcileProvider();
    }

    public function restore(JamfSyncService $sync)
    {
        return $sync->restore();
    }

    public function deleteAsset(int $asset, AssetDeletionService $deletion)
    {
        $deletion->delete($asset);

        return response()->noContent();
    }

    public function deleteEmployee(int $employee, AssetDeletionService $deletion)
    {
        $deletion->deleteEmployee($employee);

        return response()->noContent();
    }
}
