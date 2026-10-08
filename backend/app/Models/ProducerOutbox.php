<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ProducerOutbox extends Model
{
    protected $table = 'producer_outbox';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'published_at' => 'datetime', 'next_attempt_at' => 'datetime'];
    }
}
