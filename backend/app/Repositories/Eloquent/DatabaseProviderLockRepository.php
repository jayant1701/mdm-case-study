<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Persistence\ProviderLockRepository;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DatabaseProviderLockRepository implements ProviderLockRepository
{
    public function acquire(string $provider): void
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Provider locks require an active transaction.');
        }
        if (! DB::table('sync_locks')->where('provider', $provider)->lockForUpdate()->first()) {
            throw new RuntimeException('No configured lock row for provider: '.$provider);
        }
    }
}
