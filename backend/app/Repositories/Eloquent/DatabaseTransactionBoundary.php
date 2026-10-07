<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Persistence\ProviderLockRepository;
use App\Contracts\Persistence\TransactionBoundary;
use Illuminate\Support\Facades\DB;

final class DatabaseTransactionBoundary implements TransactionBoundary
{
    public function __construct(private ProviderLockRepository $locks) {}

    public function run(callable $operation, int $attempts = 1): mixed
    {
        return DB::transaction($operation, $attempts);
    }

    public function withProviderLock(string $provider, callable $operation, int $attempts = 1): mixed
    {
        return $this->run(function () use ($provider, $operation) {
            $this->locks->acquire($provider);

            return $operation();
        }, $attempts);
    }
}
