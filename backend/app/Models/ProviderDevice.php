<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ProviderDevice extends Model
{
    protected $table = 'provider_devices';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['device' => 'array', 'version' => 'integer', 'producer_generation' => 'integer'];
    }
}
