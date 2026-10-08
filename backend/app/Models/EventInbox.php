<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class EventInbox extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'version' => 'integer', 'producer_generation' => 'integer', 'processed_at' => 'datetime'];
    }
}
