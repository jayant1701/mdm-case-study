<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Persistence\DeviceModelRepository;
use App\Models\DeviceModel;
use RuntimeException;

final class EloquentDeviceModelRepository implements DeviceModelRepository
{
    public function upsertIdentity(array $presentFields): DeviceModel
    {
        $model = DeviceModel::firstOrNew(['manufacturer' => $presentFields['manufacturer'], 'model_identifier' => $presentFields['model_identifier']]);
        foreach (['model_name', 'platform', 'processor_type', 'core_count'] as $field) {
            if ($model->exists && array_key_exists($field, $presentFields) && $model->{$field} !== null && $presentFields[$field] !== null && (string) $model->{$field} !== (string) $presentFields[$field]) {
                throw new RuntimeException('Conflicting immutable device-model field '.$field.' for '.$model->manufacturer.' '.$model->model_identifier.'.');
            }
        }
        $model->fill(array_filter($presentFields, fn ($value) => $value !== null));
        $model->save();

        return $model;
    }
}
