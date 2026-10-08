<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class DeletedEmployee extends Model
{
    protected $primaryKey = 'employee_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['deleted_at' => 'datetime'];
    }

    public function assets()
    {
        return $this->hasMany(Asset::class, 'deleted_employee_id', 'employee_id');
    }
}
