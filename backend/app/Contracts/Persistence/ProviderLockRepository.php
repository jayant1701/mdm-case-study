<?php

namespace App\Contracts\Persistence;

interface ProviderLockRepository
{
    /** Must be called inside a transaction; lock lifetime equals transaction lifetime. */
    public function acquire(string $provider): void;
}
