<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class SyncRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['counters' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
