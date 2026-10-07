<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Asset;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;

final class RefJamfDeviceSyncService
{
    public function sync(array $jamfDevices): array
    {
        $result = [
            'created_assets' => 0,
            'updated_assets' => 0,
            'skipped_unassigned' => 0,
            'skipped_missing_serial' => 0,
        ];

        DB::transaction(function () use ($jamfDevices, &$result) {
            foreach ($jamfDevices as $device) {
                $serial = $this->serial($device);

                if ($serial === null) {
                    $result['skipped_missing_serial']++;
                    continue;
                }

                $email = $this->email($device);

                if ($email === null) {
                    $result['skipped_unassigned']++;
                    continue;
                }

                $employee = Employee::query()->firstOrNew(['email' => $email]);
                $employee->name = $this->name($device) ?? $employee->name;
                $employee->phone = $this->phone($device) ?? $employee->phone;
                $employee->save();

                $asset = Asset::query()->firstOrNew(['serial_code' => $serial]);

                $asset->employee_id = $employee->id;
                $asset->device_name = $this->deviceName($device);
                $asset->provider = 'jamf';
                $asset->attributes = [
                    'model' => data_get($device, 'hardware.model') ?? data_get($device, 'model'),
                    'ram_gb' => $this->ramGb($device),
                    'storage_gb' => $this->storageGb($device),
                ];

                $wasNew = !$asset->exists;
                $asset->save();

                $wasNew ? $result['created_assets']++ : $result['updated_assets']++;
            }
        });

        return $result;
    }

    private function serial(array $device): ?string
    {
        $serial = data_get($device, 'hardware.serialNumber');
        if (!is_string($serial)) {
            return null;
        }

        $serial = trim($serial);
        return $serial !== '' ? $serial : null;
    }

    private function email(array $device): ?string
    {
        $email = data_get($device, 'userAndLocation.email')
            ?? data_get($device, 'userAndLocation.emailAddress')
            ?? data_get($device, 'username');

        if (!is_string($email)) {
            return null;
        }

        $email = strtolower(trim($email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function name(array $device): ?string
    {
        $name = data_get($device, 'userAndLocation.realname')
            ?? data_get($device, 'userAndLocation.realName');

        if (!is_string($name)) {
            return null;
        }

        $name = trim($name);
        return $name !== '' ? $name : null;
    }

    private function phone(array $device): ?string
    {
        $phone = data_get($device, 'userAndLocation.phone')
            ?? data_get($device, 'userAndLocation.phoneNumber');

        if (!is_string($phone)) {
            return null;
        }

        $phone = trim($phone);
        return $phone !== '' ? $phone : null;
    }

    private function deviceName(array $device): string
    {
        $name = data_get($device, 'general.displayName')
            ?? data_get($device, 'general.name')
            ?? data_get($device, 'hardware.model')
            ?? data_get($device, 'model')
            ?? data_get($device, 'name');

        return is_string($name) && trim($name) !== '' ? trim($name) : 'Unknown';
    }

    private function ramGb(array $device): ?float
    {
        $mb = data_get($device, 'hardware.totalRamMegabytes');
        if (!is_numeric($mb) || (float)$mb <= 0) {
            return null;
        }

        return round(((float)$mb) / 1024, 2);
    }

    private function storageGb(array $device): ?float
    {
        $disks = data_get($device, 'storage.disks');
        if (!is_array($disks)) {
            return null;
        }

        $totalMb = 0.0;
        foreach ($disks as $disk) {
            $size = is_array($disk) ? ($disk['sizeMegabytes'] ?? null) : null;
            if (is_numeric($size)) {
                $totalMb += (float)$size;
            }
        }

        if ($totalMb <= 0) {
            return null;
        }

        return round($totalMb / 1024, 2);
    }
}
