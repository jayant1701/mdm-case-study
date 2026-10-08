<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class DeviceModel extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['core_count' => 'integer'];
    }

    public function assets()
    {
        return $this->hasMany(Asset::class);
    }
}
