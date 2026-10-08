<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class DeletedAsset extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['deleted_at' => 'datetime'];
    }
}
