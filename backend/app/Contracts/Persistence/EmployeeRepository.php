<?php

namespace App\Contracts\Persistence;

use App\Models\Employee;
use Illuminate\Support\Collection;

interface EmployeeRepository
{
    public function upsertByEmail(string $email, array $presentFields): Employee;

    public function count(): int;

    public function listWithAssets(): Collection;

    public function details(int $id): Employee;

    public function deleteLocal(int $id): void;

    /** Provider device IDs retained by assets of locally deleted employees. */
    public function deletedEmployeeProviderIds(string $provider): array;
}
