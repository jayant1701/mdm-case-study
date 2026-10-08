<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class DeviceEventState extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['device' => 'array', 'version' => 'integer', 'producer_generation' => 'integer'];
    }
}
