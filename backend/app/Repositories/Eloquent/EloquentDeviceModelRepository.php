<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Persistence\DeviceModelRepository;
use App\Models\DeviceModel;

final class EloquentDeviceModelRepository implements DeviceModelRepository
{
    public function upsertIdentity(array $presentFields): DeviceModel
    {
        $model = DeviceModel::firstOrNew(['manufacturer' => $presentFields['manufacturer'], 'model_identifier' => $presentFields['model_identifier']]);
        $model->fill($presentFields);
        $model->save();

        return $model;
    }
}
