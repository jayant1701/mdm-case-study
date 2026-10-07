<?php

namespace App\Data;

final readonly class DeviceSnapshot
{
    /** @param list<DeviceRecord> $devices */
    public function __construct(public array $devices, public array $warnings = []) {}
}
