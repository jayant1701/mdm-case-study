<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Persistence\EmployeeRepository;
use App\Models\Employee;
use Illuminate\Support\Collection;

final class EloquentEmployeeRepository implements EmployeeRepository
{
    public function upsertByEmail(string $email, array $presentFields): Employee
    {
        $employee = Employee::withTrashed()->firstOrNew(['email' => $email]);
        $employee->fill($presentFields);
        $employee->deleted_at = null;
        $employee->save();

        return $employee;
    }

    public function count(): int
    {
        return Employee::count();
    }

    public function listWithAssets(): Collection
    {
        return Employee::with('assets')->orderBy('email')->get();
    }

    public function deleteLocal(int $id): void
    {
        // The caller holds a transaction and provider lock.
        // Retain only identity so existing asset assignments remain attributable.
        $employee = Employee::findOrFail($id);
        $employee->fill(['name' => null, 'phone' => null, 'deleted_at' => now()])->save();
    }
}
