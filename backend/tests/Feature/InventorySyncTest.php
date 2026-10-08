<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Employee;
use App\Models\SyncRun;
use App\Services\DeviceEventSimulator;
use App\Services\JamfSyncService;
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
        $employee = Employee::where('email', 'alex.smith@company.test')->firstOrFail();
        $this->getJson('/api/employees/'.$employee->id)
            ->assertOk()
            ->assertJsonPath('email', 'alex.smith@company.test')
            ->assertJsonCount(2, 'assets')
            ->assertJsonPath('assets.0.device_model.model_identifier', 'MacBookPro18,3')
            ->assertJsonPath('assets.0.specifications.ram_gb', 16);
        $asset = Asset::where('provider_device_id', '101')->firstOrFail();
        $this->assertSame('alex.smith', $asset->employee->username);
        $this->assertSame('Backend Engineer', $asset->employee->position);
        $this->assertSame('Mac', $asset->deviceModel->platform);
        $this->assertSame('Apple M1 Pro', $asset->deviceModel->processor_type);
        $this->assertSame(10, $asset->deviceModel->core_count);
        $this->assertSame('2025-11-20 09:15:00', $asset->report_date->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-05 10:30:12', $asset->last_contact_at->format('Y-m-d H:i:s'));
        $this->assertNull($asset->site_provider_id);
        $this->assertNull($asset->site_name);
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
        foreach (['make', 'modelIdentifier', 'model', 'processorType', 'coreCount'] as $field) {
            $this->source['results'][1]['hardware'][$field] = $this->source['results'][0]['hardware'][$field];
        }$this->write();
        $this->sync();
        $a = Asset::where('provider_device_id', '101')->first();
        $b = Asset::where('provider_device_id', '102')->first();
        $this->assertSame($a->device_model_id, $b->device_model_id);
        $this->assertSame('Apple M1 Pro', $a->deviceModel->processor_type);
        $this->assertSame(10, $a->deviceModel->core_count);
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
        $this->assertDatabaseHas('deleted_assets', ['serial_code' => $asset->serial_code]);
        $this->sync();
        $this->assertDatabaseCount('assets', 7);
        $this->assertDatabaseMissing('deleted_assets', ['serial_code' => $asset->serial_code]);
        $employee = Employee::where('email', $email)->first();
        $this->deleteJson('/api/employees/'.$employee->id)->assertNoContent();
        $this->assertDatabaseHas('assets', ['employee_id' => null, 'deleted_employee_id' => $employee->id]);
        $this->assertDatabaseMissing('employees', ['id' => $employee->id]);
        $this->assertDatabaseHas('deleted_employees', ['employee_id' => $employee->id, 'email' => $email]);
        $this->sync();
        $this->assertDatabaseCount('employees', 6);
        $this->assertSame(0, Asset::whereNull('employee_id')->count());
        $this->assertDatabaseMissing('deleted_employees', ['employee_id' => $employee->id]);
    }

    public function test_targeted_restoration_missing_and_unassigned(): void
    {
        $this->sync();
        $a = Asset::where('provider_device_id', '101')->first();
        $this->deleteJson('/api/assets/'.$a->id)->assertNoContent();
        app(JamfSyncService::class)->restore();
        $this->assertDatabaseCount('assets', 7);
        $a = Asset::where('provider_device_id', '101')->first();
        $this->deleteJson('/api/assets/'.$a->id);
        $this->source['results'][0]['userAndLocation']['email'] = null;
        $this->write();
        app(DeviceEventSimulator::class)->simulate();
        app(JamfSyncService::class)->restore();
        $this->assertDatabaseMissing('deleted_assets', ['serial_code' => $a->serial_code]);
        $this->assertDatabaseCount('assets', 6);
        $b = Asset::where('provider_device_id', '102')->first();
        $this->deleteJson('/api/assets/'.$b->id);
        array_splice($this->source['results'], 1, 1);
        $this->source['totalCount'] = 6;
        $this->write();
        app(DeviceEventSimulator::class)->simulate();
        app(JamfSyncService::class)->restore();
        $this->assertDatabaseMissing('deleted_assets', ['serial_code' => $b->serial_code]);
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

    public function test_older_report_date_cannot_overwrite_newer_asset_state(): void
    {
        $this->sync();
        $this->source['results'][0]['general']['reportDate'] = '2025-01-01T00:00:00Z';
        $this->source['results'][0]['hardware']['totalRamMegabytes'] = 65536;
        $this->write();

        $this->postJson('/api/sync')->assertStatus(422);

        $asset = Asset::where('provider_device_id', '101')->firstOrFail();
        $this->assertSame(16.0, $asset->specifications->ram_gb);
        $this->assertSame('2025-11-20 09:15:00', $asset->report_date->format('Y-m-d H:i:s'));
    }

    public function test_conflicting_immutable_model_metadata_rolls_back(): void
    {
        $this->sync();
        $this->source['results'][0]['hardware']['processorType'] = 'Different processor';
        $this->source['results'][0]['general']['reportDate'] = '2026-01-06T00:00:00Z';
        $this->write();

        $this->postJson('/api/sync')->assertStatus(422);

        $asset = Asset::where('provider_device_id', '101')->firstOrFail();
        $this->assertSame('Apple M1 Pro', $asset->deviceModel->processor_type);
    }

    public function test_missing_model_fields_preserve_reference_and_explicit_null_clears(): void
    {
        $this->sync();
        $original = Asset::where('provider_device_id', '101')->first()->device_model_id;
        unset($this->source['results'][0]['hardware']['make'], $this->source['results'][0]['hardware']['modelIdentifier']);
        $this->write();
        $this->sync();
        $this->assertSame($original, Asset::where('provider_device_id', '101')->first()->device_model_id);
        $this->source['results'][0]['hardware']['modelIdentifier'] = null;
        $this->write();
        $this->sync();
        $this->assertNull(Asset::where('provider_device_id', '101')->first()->device_model_id);
    }
}
