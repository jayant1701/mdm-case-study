<?php

namespace App\Contracts\Persistence;

interface TransactionBoundary
{
    public function run(callable $operation, int $attempts = 1): mixed;

    public function withProviderLock(string $provider, callable $operation, int $attempts = 1): mixed;
}
