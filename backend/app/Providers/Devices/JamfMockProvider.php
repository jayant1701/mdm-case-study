<?php

namespace App\Providers\Devices;

use App\Contracts\DeviceProvider;
use App\Data\DeviceRecord;
use App\Data\DeviceSnapshot;
use Carbon\CarbonImmutable;
use RuntimeException;

final class JamfMockProvider implements DeviceProvider
{
    public function snapshot(): DeviceSnapshot
    {
        $path = config('mdm.mock_path');
        if (! is_readable($path)) {
            throw new RuntimeException('The Jamf mock file cannot be read.');
        }
        try {
            $response = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('Invalid Jamf JSON.', 0, $e);
        }

        return $this->parseResponse($response);
    }

    public function parseResponse(array $response): DeviceSnapshot
    {
        if (! is_array($response) || ! isset($response['results']) || ! is_array($response['results']) || ! array_is_list($response['results']) || ! isset($response['totalCount']) || ! is_int($response['totalCount']) || $response['totalCount'] !== count($response['results'])) {
            throw new RuntimeException('Expected a complete snapshot with matching totalCount and results.');
        }
        $records = [];
        $serials = [];
        $ids = [];
        $warnings = [];
        $employees = [];
        $models = [];
        foreach ($response['results'] as $row) {
            if (! is_array($row) || ! isset($row['hardware']) || ! is_array($row['hardware']) || ! isset($row['userAndLocation']) || ! is_array($row['userAndLocation'])) {
                throw new RuntimeException('Malformed device record: hardware and userAndLocation must be objects.');
            }
            $record = $this->map($row);
            if ($record->serial !== null) {
                if (isset($serials[$record->serial])) {
                    throw new RuntimeException('Duplicate serial in snapshot: '.$record->serial);
                }
                $serials[$record->serial] = true;
            }
            if ($record->providerId !== null) {
                if (isset($ids[$record->providerId])) {
                    throw new RuntimeException('Duplicate provider device ID: '.$record->providerId);
                }
                $ids[$record->providerId] = true;
            }
            $records[] = $record;
        }
        // Conflict policy is independent of source order. Later sorted records win.
        usort($records, fn ($a, $b) => strcmp($a->providerId ?? $a->serial ?? '', $b->providerId ?? $b->serial ?? ''));
        foreach ($records as $r) {
            if ($r->email) {
                if (isset($employees[$r->email]) && $employees[$r->email] !== $r->employee) {
                    $warnings[] = 'Conflicting employee metadata for '.$r->email.'; sorted provider ID precedence applied.';
                }
                $employees[$r->email] = array_merge($employees[$r->email] ?? [], $r->employee);
            }
            if ($r->model) {
                $key = $r->model['manufacturer'].'|'.$r->model['model_identifier'];
                if (isset($models[$key]) && $models[$key] !== $r->model) {
                    foreach ($r->model as $field => $value) {
                        if ($value !== null && ($models[$key][$field] ?? null) !== null && $models[$key][$field] !== $value) {
                            throw new RuntimeException('Conflicting immutable model metadata for '.$key.' field '.$field.'.');
                        }
                    }
                }
                $models[$key] = array_replace($models[$key] ?? [], array_filter($r->model, fn ($value) => $value !== null));
            }
        }
        $records = array_map(fn ($r) => new DeviceRecord($r->providerId, $r->serial, $r->email, $r->email ? ($employees[$r->email] ?? $r->employee) : $r->employee, $r->model ? ($models[$r->model['manufacturer'].'|'.$r->model['model_identifier']] ?? $r->model) : null, $r->asset, $r->specifications, $r->preserveModel), $records);

        return new DeviceSnapshot($records, array_values(array_unique($warnings)));
    }

    public function findByProviderId(string $id): ?DeviceRecord
    {
        foreach ($this->snapshot()->devices as $device) {
            if ($device->providerId === $id) {
                return $device;
            }
        }

        return null;
    }

    private function map(array $row): DeviceRecord
    {
        $h = $row['hardware'];
        $u = $row['userAndLocation'];
        $general = $row['general'] ?? [];
        if (! is_array($general)) {
            throw new RuntimeException('Invalid general section.');
        }
        $email = $this->text($u['email'] ?? $u['emailAddress'] ?? null);
        $email = $email ? strtolower($email) : null;
        if ($email && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid employee email; refusing destructive reconciliation.');
        }
        $serial = $this->text($h['serialNumber'] ?? null);
        $serial = $serial ? strtoupper($serial) : null;
        $employee = $this->fields($u, ['username' => 'username', 'realname' => 'name', 'position' => 'position', 'phone' => 'phone']);
        $identifier = $this->text($h['modelIdentifier'] ?? null);
        $make = $this->text($h['make'] ?? null);
        $model = $identifier && $make ? ['manufacturer' => $make, 'model_identifier' => $identifier] + $this->fields($h, ['model' => 'model_name', 'processorType' => 'processor_type']) : null;
        if ($model !== null) {
            $model['platform'] = $this->text($general['platform'] ?? null);
            if (array_key_exists('coreCount', $h)) {
                $coreCount = $h['coreCount'];
                if ($coreCount !== null && (! is_numeric($coreCount) || (float) $coreCount !== (float) (int) $coreCount || $coreCount < 0)) {
                    throw new RuntimeException('Core count must be a non-negative integer.');
                }
                $model['core_count'] = $coreCount === null ? null : (int) $coreCount;
            }
        }
        $asset = ['device_name' => $this->text($general['name'] ?? null) ?? $this->text($h['model'] ?? null) ?? 'Unknown'];
        if (array_key_exists('udid', $row)) {
            $asset['udid'] = $this->text($row['udid']);
        }
        if (array_key_exists('reportDate', $general)) {
            $asset['report_date'] = $this->date($general['reportDate']);
        }
        if (array_key_exists('lastContactTime', $general)) {
            $asset['last_contact_at'] = $this->date($general['lastContactTime']);
        }
        if (array_key_exists('site', $general)) {
            if (! is_array($general['site'])) {
                throw new RuntimeException('Invalid site value.');
            }
            $siteId = $this->text($general['site']['id'] ?? null);
            $siteName = $this->text($general['site']['name'] ?? null);
            $none = $siteId === '-1' || $siteName === null || strcasecmp($siteName, 'None') === 0;
            $asset['site_provider_id'] = $none ? null : $siteId;
            $asset['site_name'] = $none ? null : $siteName;
        }
        $purchasing = $row['purchasing'] ?? [];
        if (! is_array($purchasing)) {
            throw new RuntimeException('Invalid purchasing section.');
        }
        if (array_key_exists('purchased', $purchasing)) {
            if (! is_bool($purchasing['purchased']) && $purchasing['purchased'] !== null) {
                throw new RuntimeException('Invalid purchased value.');
            }
            $asset['purchased'] = $purchasing['purchased'];
        }
        if (array_key_exists('poDate', $purchasing)) {
            $asset['purchase_date'] = $this->date($purchasing['poDate'], true);
        }
        $specs = [];
        foreach (['totalRamMegabytes' => 'ram_gb', 'batteryCapacityPercent' => 'battery_capacity_percent'] as $source => $target) {
            if (array_key_exists($source, $h)) {
                $v = $h[$source];
                if ($v !== null && (! is_numeric($v) || $v < 0)) {
                    throw new RuntimeException('Invalid numeric hardware field: '.$source);
                }
                $specs[$target] = $v === null ? null : ($source === 'totalRamMegabytes' ? round((float) $v / 1024, 2) : (float) $v);
            }
        }
        if (isset($row['storage']) && array_key_exists('disks', $row['storage'])) {
            $disks = $row['storage']['disks'];
            if ($disks === null) {
                $specs['storage_gb'] = null;
            } else {
                if (! is_array($disks)) {
                    throw new RuntimeException('Invalid storage disks.');
                }
                $sum = 0;
                foreach ($disks as $disk) {
                    $size = $disk['sizeMegabytes'] ?? null;
                    if ($size !== null && (! is_numeric($size) || $size < 0)) {
                        throw new RuntimeException('Invalid disk size.');
                    }
                    $sum += (float) $size;
                }
                $specs['storage_gb'] = $sum ? round($sum / 1024, 2) : null;
            }
        }

        return new DeviceRecord($this->text($row['id'] ?? null), $serial, $email, $employee, $model, $asset, $specs, ! array_key_exists('modelIdentifier', $h) && ! array_key_exists('make', $h));
    }

    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) && ! is_int($value)) {
            throw new RuntimeException('Expected a string value.');
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function fields(array $source, array $mapping): array
    {
        $result = [];
        foreach ($mapping as $from => $to) {
            if (array_key_exists($from, $source)) {
                $result[$to] = $this->text($source[$from]);
            }
        }

        return $result;
    }

    private function date(mixed $value, bool $dateOnly = false): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || trim($value) === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}(?:T.*)?$/', $value)) {
            throw new RuntimeException('Invalid source date.');
        }
        try {
            $date = CarbonImmutable::parse($value);

            return $dateOnly ? $date->toDateString() : $date->utc()->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            throw new RuntimeException('Invalid source date.', 0, $e);
        }
    }
}
