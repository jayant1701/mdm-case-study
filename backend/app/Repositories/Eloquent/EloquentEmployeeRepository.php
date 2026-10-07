<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Persistence\EmployeeRepository;
use App\Models\Employee;
use Illuminate\Support\Collection;

final class EloquentEmployeeRepository implements EmployeeRepository
{
    public function upsertByEmail(string $email, array $presentFields): Employee
    {
        $employee = Employee::firstOrNew(['email' => $email]);
        $employee->fill($presentFields);
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
        Employee::findOrFail($id)->delete();
    }
}
