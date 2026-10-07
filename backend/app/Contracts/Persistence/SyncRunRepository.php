<?php

namespace App\Contracts\Persistence;

use App\Models\SyncRun;
use Illuminate\Support\Collection;

interface SyncRunRepository
{
    public function start(string $provider, string $mode): SyncRun;

    public function succeed(int $id, array $counters): SyncRun;

    public function fail(int $id, string $error): SyncRun;

    public function recordFailedAttempt(string $provider, string $mode, string $error): void;

    public function recent(int $limit): Collection;
}
