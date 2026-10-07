<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Employee;
use App\Models\SyncRun;
use App\Services\SyncCoordinator;
use Tests\InventoryTestCase;

class InventorySyncTest extends InventoryTestCase
{
    public function test_initial_repeat_and_lists(): void
    {
        $first = $this->sync();
        $this->assertSame(7, $first->counters['created_assets']);
        $this->assertDatabaseCount('employees', 6);
        $this->assertDatabaseCount('asset_attributes', 7);
        $second = $this->sync();
        $this->assertSame(7, $second->counters['unchanged_assets']);
        $this->assertDatabaseCount('assets', 7);
        $this->getJson('/api/assets')->assertOk()->assertJsonCount(7, 'data')->assertJsonPath('data.0.specifications.ram_gb', 16);
        $this->getJson('/api/employees')->assertOk()->assertJsonCount(6, 'data');
    }

    public function test_ram_reassignment_and_explicit_null(): void
    {
        $this->sync();
        $this->source['results'][0]['hardware']['totalRamMegabytes'] = 32768;
        $this->source['results'][0]['userAndLocation']['email'] = 'NEW@company.test';
        $this->source['results'][0]['userAndLocation']['phone'] = null;
        $this->write();
        $run = $this->sync();
        $asset = Asset::where('provider_device_id', '101')->first();
        $this->assertSame('new@company.test', $asset->employee->email);
        $this->assertNull($asset->employee->phone);
        $this->assertSame(32.0, $asset->specifications->ram_gb);
        $this->assertSame(1, $run->counters['updated_assets']);
    }

    public function test_shared_model_does_not_share_configurations(): void
    {
        foreach (['make', 'modelIdentifier', 'model'] as $field) {
            $this->source['results'][1]['hardware'][$field] = $this->source['results'][0]['hardware'][$field];
        }$this->write();
        $this->sync();
        $a = Asset::where('provider_device_id', '101')->first();
        $b = Asset::where('provider_device_id', '102')->first();
        $this->assertSame($a->device_model_id, $b->device_model_id);
        $this->assertSame(16.0, $a->specifications->ram_gb);
        $this->assertSame(8.0, $b->specifications->ram_gb);
    }

    public function test_deleted_asset_and_employee_recreated_on_full_sync(): void
    {
        $this->sync();
        $asset = Asset::first();
        $old = $asset->id;
        $email = $asset->employee->email;
        $this->deleteJson('/api/assets/'.$old)->assertNoContent();
        $this->assertDatabaseMissing('asset_attributes', ['asset_id' => $old]);
        $this->assertDatabaseHas('restore_tasks', ['serial_code' => $asset->serial_code, 'status' => 'pending']);
        $this->sync();
        $this->assertDatabaseCount('assets', 7);
        $this->assertDatabaseHas('restore_tasks', ['status' => 'restored']);
        $employee = Employee::where('email', $email)->first();
        $this->deleteJson('/api/employees/'.$employee->id)->assertNoContent();
        $this->assertDatabaseHas('assets', ['employee_id' => null]);
        $this->sync();
        $this->assertDatabaseCount('employees', 6);
        $this->assertSame(0, Asset::whereNull('employee_id')->count());
    }

    public function test_targeted_restoration_missing_and_unassigned(): void
    {
        $this->sync();
        $a = Asset::where('provider_device_id', '101')->first();
        $this->deleteJson('/api/assets/'.$a->id)->assertNoContent();
        app(SyncCoordinator::class)->restore();
        $this->assertDatabaseCount('assets', 7);
        $a = Asset::where('provider_device_id', '101')->first();
        $this->deleteJson('/api/assets/'.$a->id);
        $this->source['results'][0]['userAndLocation']['email'] = null;
        $this->write();
        app(SyncCoordinator::class)->restore();
        $this->assertDatabaseHas('restore_tasks', ['status' => 'upstream_unassigned']);
        $this->assertDatabaseCount('assets', 6);
        $b = Asset::where('provider_device_id', '102')->first();
        $this->deleteJson('/api/assets/'.$b->id);
        array_splice($this->source['results'], 1, 1);
        $this->source['totalCount'] = 6;
        $this->write();
        app(SyncCoordinator::class)->restore();
        $this->assertDatabaseHas('restore_tasks', ['serial_code' => $b->serial_code, 'status' => 'upstream_missing']);
    }

    public function test_unassigned_and_absent_are_removed_only_after_complete_snapshot(): void
    {
        $this->sync();
        $this->source['results'][0]['userAndLocation']['email'] = null;
        array_pop($this->source['results']);
        $this->source['totalCount'] = 6;
        $this->write();
        $run = $this->sync();
        $this->assertSame(2, $run->counters['removed_assets']);
        $this->assertDatabaseCount('assets', 5);
        $this->assertDatabaseCount('employees', 6);
    }

    public function test_invalid_snapshots_preserve_existing_assets_and_record_failure(): void
    {
        $this->sync();
        $original = $this->source;
        foreach (['truncated', 'duplicate', 'invalid_email', 'date', 'structure'] as $kind) {
            $this->source = $original;
            if ($kind === 'truncated') {
                array_pop($this->source['results']);
            }
            if ($kind === 'duplicate') {
                $this->source['results'][1]['hardware']['serialNumber'] = $this->source['results'][0]['hardware']['serialNumber'];
            }
            if ($kind === 'invalid_email') {
                $this->source['results'][0]['userAndLocation']['email'] = 'bad';
            }
            if ($kind === 'date') {
                $this->source['results'][0]['general']['reportDate'] = '';
            }
            if ($kind === 'structure') {
                $this->source['results'][0]['hardware'] = null;
            }
            $this->write();
            $this->postJson('/api/sync')->assertStatus(422);
            $this->assertDatabaseCount('assets', 7);
            $this->assertSame('failed', SyncRun::latest('id')->first()->status);
        }
    }

    public function test_missing_serial_preserves_known_device(): void
    {
        $this->sync();
        unset($this->source['results'][0]['hardware']['serialNumber']);
        $this->write();
        $run = $this->sync();
        $this->assertSame(1, $run->counters['skipped_missing_serial']);
        $this->assertDatabaseCount('assets', 7);
    }

    public function test_missing_optional_value_preserves_but_null_clears(): void
    {
        $this->sync();
        unset($this->source['results'][0]['userAndLocation']['phone']);
        $this->write();
        $this->sync();
        $this->assertNotNull(Employee::where('email', 'alex.smith@company.test')->first()->phone);
        foreach ([0, 2] as $i) {
            $this->source['results'][$i]['userAndLocation']['phone'] = null;
        }$this->write();
        $this->sync();
        $this->assertNull(Employee::where('email', 'alex.smith@company.test')->first()->phone);
    }

    public function test_existing_provider_identity_conflict_rolls_back(): void
    {
        $this->sync();
        $this->source['results'][0]['id'] = 'new-id';
        $this->source['results'][1]['hardware']['totalRamMegabytes'] = 65536;
        $this->write();
        $this->postJson('/api/sync')->assertStatus(422);
        $this->assertSame(8.0, Asset::where('provider_device_id', '102')->first()->specifications->ram_gb);
    }
}
