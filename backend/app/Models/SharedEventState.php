<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class SharedEventState extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['generation' => 'integer'];
    }
}
