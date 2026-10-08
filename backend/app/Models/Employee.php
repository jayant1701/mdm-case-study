<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Employee extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    public function assets()
    {
        return $this->hasMany(Asset::class);
    }
}
