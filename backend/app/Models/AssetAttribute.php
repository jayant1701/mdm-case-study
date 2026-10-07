<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AssetAttribute extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['ram_gb' => 'float', 'storage_gb' => 'float', 'battery_capacity_percent' => 'float'];
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }
}
