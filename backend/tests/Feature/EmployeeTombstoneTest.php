<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\DeletedEmployee;
use App\Models\Employee;
use App\Models\EventInbox;
use App\Services\DeviceEventSimulator;
use App\Services\EventProcessor;
use App\Services\JamfSyncService;
use Illuminate\Support\Facades\Queue;
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
        $deleted = DeletedEmployee::findOrFail($employee->id);
        $this->assertSame($employee->email, $deleted->email);
        $this->getJson('/api/employees')->assertOk()->assertJsonCount(5, 'data')->assertJsonMissing(['email' => $employee->email]);
        foreach ($assetIds as $assetId) {
            $asset = Asset::findOrFail($assetId);
            $this->assertNull($asset->employee_id);
            $this->assertSame($employee->id, $asset->deleted_employee_id);
            $this->getJson('/api/assets/'.$assetId)
                ->assertOk()
                ->assertJsonPath('employee', null)
                ->assertJsonPath('deleted_employee.email', $employee->email);
        }

        $this->sync();

        $restored = Employee::where('email', $employee->email)->firstOrFail();
        $this->assertSame($employee->id, $restored->id);
        $this->assertSame('Alex Smith', $restored->name);
        $this->assertNotNull($restored->phone);
        $this->assertSame($assetIds, $restored->assets->pluck('id')->all());
        $this->assertSame(6, Employee::count());
        $this->assertDatabaseMissing('deleted_employees', ['employee_id' => $employee->id]);
    }

    public function test_deleting_asset_does_not_delete_its_employee(): void
    {
        $this->sync();
        $asset = Asset::where('provider_device_id', '102')->firstOrFail();
        $employeeId = $asset->employee_id;

        $this->deleteJson('/api/assets/'.$asset->id)->assertNoContent();

        $this->assertDatabaseMissing('assets', ['id' => $asset->id]);
        $employee = Employee::findOrFail($employeeId);
        $this->assertSame(0, $employee->assets()->count());
        $this->getJson('/api/employees')->assertOk()->assertJsonCount(6, 'data');
    }

    public function test_targeted_provider_lookup_restores_a_deleted_employee_without_a_snapshot(): void
    {
        $this->sync();
        $employee = Employee::where('email', 'alex.smith@company.test')->firstOrFail();
        $assetIds = $employee->assets->pluck('id')->all();
        $this->deleteJson('/api/employees/'.$employee->id)->assertNoContent();
        file_put_contents($this->fixture, 'not-json');

        app(JamfSyncService::class)->restore();

        $restored = Employee::where('email', $employee->email)->firstOrFail();
        $this->assertSame($employee->id, $restored->id);
        $this->assertSame($assetIds, $restored->assets->pluck('id')->all());
        $this->assertDatabaseMissing('deleted_employees', ['employee_id' => $employee->id]);
    }

    public function test_device_notification_restores_a_deleted_employee(): void
    {
        Queue::fake();
        $this->sync();
        $employee = Employee::where('email', 'alex.smith@company.test')->firstOrFail();
        $this->deleteJson('/api/employees/'.$employee->id)->assertNoContent();
        $this->source['results'][0]['hardware']['totalRamMegabytes'] = 32768;
        $this->write();

        app(DeviceEventSimulator::class)->simulate();
        $event = EventInbox::where('provider_device_id', '101')->latest('id')->firstOrFail();
        app(EventProcessor::class)->process($event->id);

        $restored = Employee::where('email', $employee->email)->firstOrFail();
        $this->assertSame($employee->id, $restored->id);
        $this->assertDatabaseMissing('deleted_employees', ['employee_id' => $employee->id]);
        $this->assertDatabaseHas('assets', ['provider_device_id' => '101', 'employee_id' => $employee->id, 'deleted_employee_id' => null]);
    }
}
