<?php

namespace App\Services;

use App\Contracts\Persistence\AssetRepository;
use App\Contracts\Persistence\DeletedAssetRepository;
use App\Contracts\Persistence\EmployeeRepository;
use App\Contracts\Persistence\TransactionBoundary;

final class AssetDeletionService
{
    public function __construct(private AssetRepository $assets, private EmployeeRepository $employees, private DeletedAssetRepository $deletedAssets, private TransactionBoundary $transactions) {}

    public function delete(int $assetId): void
    {
        $this->transactions->withProviderLock('jamf', function () use ($assetId) {
            $asset = $this->assets->requireForDeletion($assetId);
            $this->deletedAssets->recordLocalDeletion($asset);
            $this->assets->deleteLocal($assetId);
        });
    }

    public function deleteEmployee(int $employeeId): void
    {
        $this->transactions->withProviderLock('jamf', fn () => $this->employees->deleteLocal($employeeId));
    }
}
