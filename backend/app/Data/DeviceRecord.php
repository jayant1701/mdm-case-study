<?php

namespace App\Data;

final readonly class DeviceRecord
{
    public function __construct(public ?string $providerId, public ?string $serial, public ?string $email, public array $employee, public ?array $model, public array $asset, public array $specifications, public bool $preserveModel = false) {}
}
