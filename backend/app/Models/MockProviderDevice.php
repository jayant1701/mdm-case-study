<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class MockProviderDevice extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['device' => 'array'];
    }
}
