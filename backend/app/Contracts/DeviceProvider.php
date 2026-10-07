<?php

namespace App\Contracts;

use App\Data\DeviceRecord;
use App\Data\DeviceSnapshot;

interface DeviceProvider
{
    public function snapshot(): DeviceSnapshot;

    public function findByProviderId(string $id): ?DeviceRecord;
}
