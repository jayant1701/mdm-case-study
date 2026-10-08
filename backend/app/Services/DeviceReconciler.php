<?php

namespace App\Services;

use App\Contracts\Persistence\AssetRepository;
use App\Contracts\Persistence\DeletedAssetRepository;
use App\Contracts\Persistence\DeviceModelRepository;
use App\Contracts\Persistence\EmployeeRepository;
use App\Data\DeviceRecord;
use RuntimeException;

final class DeviceReconciler
{
    public function __construct(private AssetRepository $assets, private EmployeeRepository $employees, private DeviceModelRepository $models, private DeletedAssetRepository $deletedAssets, private SharedMetadataPolicy $shared) {}

    public function validateIdentities(array $devices): void
    {
        foreach ($devices as $device) {
            if (! $device->serial) {
                continue;
            }
            $asset = $this->assets->findBySerial($device->serial);
            if ($asset && ($asset->provider !== 'jamf' || ($asset->provider_device_id !== null && $device->providerId !== null && $asset->provider_device_id !== $device->providerId))) {
                throw new RuntimeException('Serial is already linked to another provider identity: '.$device->serial);
            }
            if ($device->providerId && $this->assets->providerIdentityUsesDifferentSerial('jamf', $device->providerId, $device->serial)) {
                throw new RuntimeException('Provider device ID is already linked to another serial: '.$device->providerId);
            }
        }
    }

    public function apply(DeviceRecord $device, array &$counts, ?int $generation = null): void
    {
        if (! $device->serial) {
            $counts['skipped_missing_serial']++;

            return;
        }
        if (! $device->email) {
            $counts['skipped_unassigned']++;
            $counts['removed_assets'] += $this->assets->removeBySerial('jamf', $device->serial);
            $this->deletedAssets->removeByIdentity('jamf', $device->providerId, $device->serial);

            return;
        }
        $employeeFields = $generation === null ? $device->employee : $this->shared->fields('employee', $device->email, $generation, $device->employee);
        $employee = $this->employees->upsertByEmail($device->email, $employeeFields);
        $model = null;
        if ($device->model) {
            $modelFields = $device->model;
            if ($generation !== null) {
                $modelFields = ['manufacturer' => $device->model['manufacturer'], 'model_identifier' => $device->model['model_identifier']] + $this->shared->fields('model', $device->model['manufacturer'].'|'.$device->model['model_identifier'], $generation, $device->model);
            }$model = $this->models->upsertIdentity($modelFields);
        }
        $existing = $device->preserveModel ? $this->assets->findBySerial($device->serial) : null;
        $fields = $device->asset + [
            'employee_id' => $employee->id,
            'deleted_employee_id' => null,
            'device_model_id' => $device->preserveModel ? $existing?->device_model_id : $model?->id,
            'provider' => 'jamf',
            'provider_device_id' => $device->providerId,
        ];
        $result = $this->assets->persistSnapshot($device->serial, $fields, $device->specifications);
        $counts[$result->created ? 'created_assets' : ($result->changed ? 'updated_assets' : 'unchanged_assets')]++;
        $this->deletedAssets->removeByIdentity('jamf', $device->providerId, $device->serial);
    }

    public function removeProviderDevice(string $id, array &$counts): void
    {
        $counts['removed_assets'] += $this->assets->removeProviderDevice('jamf', $id);
        $this->deletedAssets->removeByIdentity('jamf', $id, '');
    }

    public function removeAbsent(array $devices, array &$counts): void
    {
        $serials = array_values(array_filter(array_map(fn ($device) => $device->serial, $devices)));
        // Policy: skipped rows with a missing serial protect their known provider identity.
        $protectedIds = array_values(array_filter(array_map(fn ($device) => $device->serial === null ? $device->providerId : null, $devices)));
        $counts['removed_assets'] += $this->assets->removeOutsideSnapshot('jamf', $serials, $protectedIds);
    }
}
