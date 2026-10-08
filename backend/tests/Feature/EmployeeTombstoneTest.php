<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Employee;
use Tests\InventoryTestCase;

class EmployeeTombstoneTest extends InventoryTestCase
{
    public function test_local_employee_deletion_hides_employee_but_preserves_asset_identity(): void
    {
        $this->sync();
        $employee = Employee::where('email', 'alex.smith@company.test')->firstOrFail();
        $assetIds = $employee->assets->pluck('id')->all();

        $this->deleteJson('/api/employees/'.$employee->id)->assertNoContent();

        $this->assertSame(5, Employee::count());
        $this->assertSame(6, Employee::withTrashed()->count());
        $deleted = Employee::withTrashed()->findOrFail($employee->id);
        $this->assertTrue($deleted->trashed());
        $this->assertSame($employee->email, $deleted->email);
        $this->assertNull($deleted->name);
        $this->assertNull($deleted->phone);
        $this->getJson('/api/employees')->assertOk()->assertJsonCount(5, 'data')->assertJsonMissing(['email' => $employee->email]);
        foreach ($assetIds as $assetId) {
            $asset = Asset::findOrFail($assetId);
            $this->assertSame($employee->id, $asset->employee_id);
            $this->assertTrue($asset->employee->trashed());
            $response = $this->getJson('/api/assets/'.$assetId)->assertOk()->assertJsonPath('employee.email', $employee->email);
            $this->assertNotNull($response->json('employee.deleted_at'));
        }

        $this->sync();

        $restored = Employee::where('email', $employee->email)->firstOrFail();
        $this->assertSame($employee->id, $restored->id);
        $this->assertSame('Alex Smith', $restored->name);
        $this->assertNotNull($restored->phone);
        $this->assertFalse($restored->trashed());
        $this->assertSame($assetIds, $restored->assets->pluck('id')->all());
        $this->assertSame(6, Employee::withTrashed()->count());
    }

    public function test_deleting_asset_does_not_delete_its_employee(): void
    {
        $this->sync();
        $asset = Asset::where('provider_device_id', '102')->firstOrFail();
        $employeeId = $asset->employee_id;

        $this->deleteJson('/api/assets/'.$asset->id)->assertNoContent();

        $this->assertDatabaseMissing('assets', ['id' => $asset->id]);
        $employee = Employee::findOrFail($employeeId);
        $this->assertFalse($employee->trashed());
        $this->assertSame(0, $employee->assets()->count());
        $this->getJson('/api/employees')->assertOk()->assertJsonCount(6, 'data');
    }
}
