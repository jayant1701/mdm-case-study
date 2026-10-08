<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Persistence\EmployeeRepository;
use App\Models\Asset;
use App\Models\DeletedEmployee;
use App\Models\Employee;
use Illuminate\Support\Collection;

final class EloquentEmployeeRepository implements EmployeeRepository
{
    public function upsertByEmail(string $email, array $presentFields): Employee
    {
        $employee = Employee::firstOrNew(['email' => $email]);
        $deleted = DeletedEmployee::where('email', $email)->first();
        if (! $employee->exists && $deleted) {
            $employee->id = $deleted->employee_id;
        }
        $employee->fill($presentFields);
        $employee->email = $email;
        $employee->save();
        if ($deleted) {
            Asset::where('deleted_employee_id', $deleted->employee_id)->update(['employee_id' => $employee->id, 'deleted_employee_id' => null]);
            $deleted->delete();
        }

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

    public function details(int $id): Employee
    {
        return Employee::with(['assets.deviceModel', 'assets.specifications'])->findOrFail($id);
    }

    public function deleteLocal(int $id): void
    {
        $employee = Employee::findOrFail($id);
        DeletedEmployee::updateOrCreate(['employee_id' => $employee->id], ['email' => $employee->email, 'deleted_at' => now()]);
        Asset::where('employee_id', $employee->id)->update(['employee_id' => null, 'deleted_employee_id' => $employee->id]);
        $employee->delete();
    }

    public function deletedEmployeeProviderIds(string $provider): array
    {
        return Asset::where('provider', $provider)
            ->whereNotNull('deleted_employee_id')
            ->whereNotNull('provider_device_id')
            ->distinct()
            ->orderBy('provider_device_id')
            ->pluck('provider_device_id')
            ->all();
    }
}
