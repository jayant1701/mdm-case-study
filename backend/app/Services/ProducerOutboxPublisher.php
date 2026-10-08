<?php

namespace App\Services;

use App\Contracts\EventPublisher;
use App\Models\ProducerOutbox;
use Illuminate\Support\Facades\DB;

final class ProducerOutboxPublisher
{
    public function __construct(private EventPublisher $publisher) {}

    public function drain(int $limit = 100): array
    {
        $published = 0;
        $failed = 0;
        foreach (ProducerOutbox::where('status', 'pending')->orderBy('id')->limit($limit)->pluck('id') as $id) {
            try {
                DB::transaction(function () use ($id, &$published) {
                    $row = ProducerOutbox::whereKey($id)->lockForUpdate()->first();
                    if (! $row || $row->status !== 'pending') {
                        return;
                    }$row->increment('attempts');
                    $this->publisher->publish($row->payload);
                    $row->update(['status' => 'published', 'published_at' => now(), 'last_error' => null]);
                    $published++;
                });
            } catch (\Throwable $e) {
                ProducerOutbox::whereKey($id)->where('status', 'pending')->increment('attempts', 1, ['last_error' => $e->getMessage()]);
                $failed++;
                break;
            }
        }

        return ['published' => $published, 'failed' => $failed];
    }
}
