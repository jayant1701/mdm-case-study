<?php

namespace App\Contracts\Persistence;

use App\Models\DeviceModel;

interface DeviceModelRepository
{
    public function upsertIdentity(array $presentFields): DeviceModel;
}
