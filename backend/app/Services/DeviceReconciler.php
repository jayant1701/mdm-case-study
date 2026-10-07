<?php

namespace App\Services;

use App\Data\DeviceRecord;
use App\Models\Asset;
use App\Models\AssetAttribute;
use App\Models\DeviceModel;
use App\Models\Employee;
use App\Models\RestoreTask;
use RuntimeException;

final class DeviceReconciler
{
    public function validateIdentities(array $devices): void
    {
        foreach ($devices as $d) {
            if (! $d->serial) {
                continue;
            }
            $asset = Asset::where('serial_code', $d->serial)->first();
            if ($asset && ($asset->provider !== 'jamf' || ($asset->provider_device_id !== null && $d->providerId !== null && $asset->provider_device_id !== $d->providerId))) {
                throw new RuntimeException('Serial is already linked to another provider identity: '.$d->serial);
            }
            if ($d->providerId && Asset::where('provider', 'jamf')->where('provider_device_id', $d->providerId)->where('serial_code', '!=', $d->serial)->exists()) {
                throw new RuntimeException('Provider device ID is already linked to another serial: '.$d->providerId);
            }
        }
    }

    public function apply(DeviceRecord $d, array &$counts): void
    {
        if (! $d->serial) {
            $counts['skipped_missing_serial']++;

            return;
        }
        if (! $d->email) {
            $counts['skipped_unassigned']++;
            $counts['removed_assets'] += Asset::where('provider', 'jamf')->where('serial_code', $d->serial)->delete();

            return;
        }
        $employee = Employee::firstOrNew(['email' => $d->email]);
        $employee->fill($d->employee);
        $employee->save();
        $model = null;
        if ($d->model) {
            $model = DeviceModel::firstOrNew(['manufacturer' => $d->model['manufacturer'], 'model_identifier' => $d->model['model_identifier']]);
            $model->fill($d->model);
            $model->save();
        }
        $asset = Asset::firstOrNew(['serial_code' => $d->serial]);
        $new = ! $asset->exists;
        $asset->fill($d->asset + ['employee_id' => $employee->id, 'device_model_id' => ($d->preserveModel ? $asset->device_model_id : $model?->id), 'provider' => 'jamf', 'provider_device_id' => $d->providerId]);
        $changed = $asset->isDirty();
        $asset->save();
        $specs = AssetAttribute::firstOrNew(['asset_id' => $asset->id]);
        $specs->fill($d->specifications);
        $changed = $changed || ! $specs->exists || $specs->isDirty();
        $specs->save();
        $counts[$new ? 'created_assets' : ($changed ? 'updated_assets' : 'unchanged_assets')]++;
        RestoreTask::where('provider', 'jamf')->where('serial_code', $d->serial)->where('status', 'pending')->update(['status' => 'restored', 'error' => null]);
    }

    public function removeProviderDevice(string $id, array &$counts): void
    {
        $counts['removed_assets'] += Asset::where('provider', 'jamf')->where('provider_device_id', $id)->delete();
    }

    public function removeAbsent(array $devices, array &$counts): void
    {
        $serials = array_values(array_filter(array_map(fn ($d) => $d->serial, $devices)));
        // A skipped row with a missing serial must not accidentally delete its known local device.
        $protectedIds = array_values(array_filter(array_map(fn ($d) => $d->serial === null ? $d->providerId : null, $devices)));
        $query = Asset::where('provider', 'jamf')->whereNotIn('serial_code', $serials);
        if ($protectedIds) {
            $query->where(fn ($q) => $q->whereNull('provider_device_id')->orWhereNotIn('provider_device_id', $protectedIds));
        }
        $counts['removed_assets'] += $query->delete();
    }
}
