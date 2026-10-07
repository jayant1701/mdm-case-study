<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Persistence\SyncRunRepository;
use App\Models\SyncRun;
use Illuminate\Support\Collection;

final class EloquentSyncRunRepository implements SyncRunRepository
{
    public function start(string $provider, string $mode): SyncRun
    {
        return SyncRun::create(['provider' => $provider, 'mode' => $mode, 'status' => 'in_progress', 'started_at' => now()]);
    }

    public function succeed(int $id, array $counters): SyncRun
    {
        $run = SyncRun::findOrFail($id);
        $run->update(['status' => 'succeeded', 'completed_at' => now(), 'counters' => $counters]);

        return $run->fresh();
    }

    public function fail(int $id, string $error): SyncRun
    {
        $run = SyncRun::findOrFail($id);
        $run->update(['status' => 'failed', 'completed_at' => now(), 'error_details' => $error]);

        return $run->fresh();
    }

    public function recordFailedAttempt(string $provider, string $mode, string $error): void
    {
        SyncRun::create(['provider' => $provider, 'mode' => $mode, 'status' => 'failed', 'started_at' => now(), 'completed_at' => now(), 'error_details' => $error]);
    }

    public function recent(int $limit): Collection
    {
        return SyncRun::latest('id')->limit($limit)->get();
    }
}
