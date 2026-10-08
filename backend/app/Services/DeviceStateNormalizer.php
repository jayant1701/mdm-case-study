<?php

namespace App\Services;

use App\Data\DeviceEvent;
use App\Data\DeviceRecord;
use App\Data\DeviceSnapshot;

final class DeviceStateNormalizer
{
    public function complete(DeviceRecord $record, ?array $previous = null): DeviceRecord
    {
        $state = DeviceEvent::fromRecord($record);
        $sameEmployee = $previous && $previous['email'] === $record->email;
        $state['employee'] = array_replace($sameEmployee ? $previous['employee'] : $state['employee'], $record->employee);
        $state['asset'] = array_replace($previous['asset'] ?? $state['asset'], $record->asset);
        $state['specifications'] = array_replace($previous['specifications'] ?? $state['specifications'], $record->specifications);
        if ($record->preserveModel) {
            $state['model'] = $previous['model'] ?? null;
        } elseif ($record->model) {
            $same = $previous && $previous['model'] && $previous['model']['manufacturer'] === $record->model['manufacturer'] && $previous['model']['model_identifier'] === $record->model['model_identifier'];
            $state['model'] = array_replace($same ? $previous['model'] : $state['model'], $record->model);
        }

        return new DeviceRecord($record->providerId, $record->serial, $record->email, $state['employee'], $state['model'], $state['asset'], $state['specifications']);
    }

    public function canonicalSnapshot(array $records, array $warnings = []): DeviceSnapshot
    {
        usort($records, fn ($a, $b) => strcmp($a->providerId ?? '', $b->providerId ?? ''));
        $employees = [];
        $models = [];
        foreach ($records as $record) {
            if ($record->email) {
                $employees[$record->email] = $record->employee;
            }if ($record->model) {
                $models[$record->model['manufacturer'].'|'.$record->model['model_identifier']] = $record->model;
            }
        }

        return new DeviceSnapshot(array_map(fn ($r) => new DeviceRecord($r->providerId, $r->serial, $r->email, $r->email ? $employees[$r->email] : $r->employee, $r->model ? $models[$r->model['manufacturer'].'|'.$r->model['model_identifier']] : null, $r->asset, $r->specifications), $records), $warnings);
    }
}
