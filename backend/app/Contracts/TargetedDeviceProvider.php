<?php

namespace App\Contracts;

use App\Data\DeviceEvent;
use App\Data\DeviceSnapshot;

interface TargetedDeviceProvider
{
    public function current(string $providerId): ?DeviceEvent;

    public function snapshot(): DeviceSnapshot;
}
