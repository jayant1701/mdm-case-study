<?php

namespace Tests\Feature;

use App\Contracts\DeviceProvider;
use App\Contracts\Persistence\DemoStateRepository;
use App\Contracts\Persistence\MockProviderRepository;
use App\Models\Asset;
use App\Models\EventInbox;
use App\Services\DeviceEventSimulator;
use App\Services\EventProcessor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\InventoryTestCase;

final class ProviderLifecycleTest extends InventoryTestCase
{
    public function test_seed_backfills_provider_store_without_duplicate_notifications(): void
    {
        Queue::fake();
        $this->sync();
        DB::table('mock_provider_devices')->delete();
        $this->postJson('/api/provider/jamf/seed')->assertStatus(202)->assertJsonPath('emitted', 0);
        $this->getJson('/api/provider/jamf/devices/101')->assertOk();
        $this->assertDatabaseCount('mock_provider_devices', 7);
        $this->assertDatabaseCount('event_inboxes', 0);
    }

    private function drain(): void
    {
        foreach (EventInbox::where('status', 'pending')->orderBy('id')->get() as $event) {
            app(EventProcessor::class)->process($event->id);
        }
    }

    public function test_provider_crud_changes_queue_complete_state_and_preserve_inventory_boundaries(): void
    {
        Queue::fake();
        $this->sync();
        $device = $this->getJson('/api/provider/jamf/devices/101')->assertOk()->json('device');
        $this->patchJson('/api/provider/jamf/devices/101', ['asset' => ['report_date' => '2026-10-09 00:00:00']])->assertOk()->assertJsonPath('emitted', 0);
        $this->patchJson('/api/provider/jamf/devices/101', ['specifications' => ['ram_gb' => 64]])->assertOk()->assertJsonPath('emitted', 1);
        $this->drain();
        $this->assertSame(64.0, Asset::where('provider_device_id', '101')->first()->specifications->ram_gb);
        $new = $device;
        $new['provider_device_id'] = '108';
        $new['serial_code'] = 'NEW-SERIAL-108';
        $new['email'] = 'new@company.test';
        $new['employee'] = ['name' => 'New Employee', 'phone' => null];
        $this->postJson('/api/provider/jamf/devices', $new)->assertCreated()->assertJsonPath('emitted', 1);
        $this->drain();
        $this->assertDatabaseCount('assets', 8);
        $this->patchJson('/api/provider/jamf/devices/108', ['email' => null])->assertOk()->assertJsonPath('emitted', 1);
        $this->drain();
        $this->assertDatabaseMissing('assets', ['provider_device_id' => '108']);
        $this->patchJson('/api/provider/jamf/devices/108', ['email' => 'new@company.test'])->assertOk();
        $this->drain();
        $this->assertDatabaseHas('assets', ['provider_device_id' => '108']);
        $this->deleteJson('/api/provider/jamf/devices/108')->assertOk()->assertJsonPath('emitted', 1);
        $this->getJson('/api/provider/jamf/devices/108')->assertNotFound();
        $this->drain();
        $this->assertDatabaseCount('assets', 7);
        $this->postJson('/api/provider/jamf/devices', $new)->assertCreated();
        $this->drain();
        $this->assertDatabaseCount('assets', 8);
        $this->patchJson('/api/provider/jamf/devices/101', ['serial_code' => 'WRONG'])->assertStatus(409);
    }

    public function test_targeted_restore_fences_old_queue_without_reading_file_or_scanning_provider(): void
    {
        Queue::fake();
        $this->sync();
        $this->patchJson('/api/provider/jamf/devices/101', ['specifications' => ['ram_gb' => 32]])->assertOk();
        $old = EventInbox::first();
        $asset = Asset::where('provider_device_id', '101')->first();
        $this->deleteJson('/api/assets/'.$asset->id)->assertNoContent();
        $this->patchJson('/api/provider/jamf/devices/101', ['specifications' => ['ram_gb' => 64]])->assertOk();
        $actual = app(MockProviderRepository::class);
        $record = $actual->find('101');
        $store = Mockery::mock(MockProviderRepository::class);
        $store->shouldReceive('find')->twice()->with('101')->andReturn($record);
        $store->shouldNotReceive('active');
        $store->shouldReceive('store')->once()->andReturnUsing(fn ($id, $device, $state) => $actual->store($id, $device, $state));
        $this->app->instance(MockProviderRepository::class, $store);
        $source = Mockery::mock(DeviceProvider::class);
        $source->shouldNotReceive('snapshot');
        $source->shouldNotReceive('findByProviderId');
        $this->app->instance(DeviceProvider::class, $source);
        file_put_contents($this->fixture, 'broken');
        $this->getJson('/api/provider/jamf/devices/101')->assertOk()->assertJsonPath('device.specifications.ram_gb', 64);
        $this->postJson('/api/restorations')->assertOk();
        $this->assertSame(64.0, Asset::where('provider_device_id', '101')->first()->specifications->ram_gb);
        app(EventProcessor::class)->process($old->id);
        $this->assertSame('ignored_stale', $old->fresh()->outcome);
        $this->assertSame(64.0, Asset::where('provider_device_id', '101')->first()->specifications->ram_gb);
    }

    public function test_local_deletions_are_listed_without_restoring_the_asset(): void
    {
        $this->sync();
        $asset = Asset::where('provider_device_id', '101')->firstOrFail();

        $this->deleteJson('/api/assets/'.$asset->id)->assertNoContent();

        $this->getJson('/api/deleted-assets')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.provider_device_id', '101')
            ->assertJsonPath('data.0.serial_code', $asset->serial_code)
            ->assertJsonPath('data.0.device_name', $asset->device_name);
        $this->assertDatabaseMissing('assets', ['provider_device_id' => '101']);
    }

    public function test_demo_inventory_reset_clears_local_state(): void
    {
        $this->sync();
        $asset = Asset::where('provider_device_id', '101')->firstOrFail();
        $this->deleteJson('/api/assets/'.$asset->id)->assertNoContent();

        app(DemoStateRepository::class)->resetInventory();

        $this->assertDatabaseCount('assets', 0);
        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseCount('restore_tasks', 0);
        $this->assertDatabaseCount('sync_runs', 0);
        $this->assertDatabaseCount('event_inboxes', 0);
        $this->assertDatabaseHas('sync_locks', ['provider' => 'jamf']);
    }

    public function test_omitted_hardware_is_preserved_in_complete_events_but_explicit_null_clears(): void
    {
        Queue::fake();
        $this->sync();
        unset($this->source['results'][0]['hardware']['totalRamMegabytes']);
        $this->write();
        $this->sync();
        $this->source['results'][0]['general']['name'] = 'Changed name';
        $this->write();
        app(DeviceEventSimulator::class)->simulate();
        $this->drain();
        $this->assertSame(16.0, Asset::where('provider_device_id', '101')->first()->specifications->ram_gb);
        $this->patchJson('/api/provider/jamf/devices/101', ['specifications' => ['ram_gb' => null]])->assertOk();
        $this->drain();
        $this->assertNull(Asset::where('provider_device_id', '101')->first()->specifications->ram_gb);
    }

    public function test_shared_model_name_omission_does_not_conflict_on_full_sync(): void
    {
        Queue::fake();
        $this->sync();
        foreach (['make', 'modelIdentifier'] as $key) {
            $this->source['results'][1]['hardware'][$key] = $this->source['results'][0]['hardware'][$key];
        }unset($this->source['results'][1]['hardware']['model']);
        $this->write();
        $this->sync();
        $this->assertDatabaseCount('assets', 7);
        $this->assertSame(Asset::where('provider_device_id', '101')->first()->device_model_id, Asset::where('provider_device_id', '102')->first()->device_model_id);
    }

    public function test_provider_mutations_survive_current_reconciliation_and_file_import_is_explicit(): void
    {
        Queue::fake();
        $this->sync();
        $this->patchJson('/api/provider/jamf/devices/101', ['specifications' => ['ram_gb' => 64]])->assertOk();
        $this->postJson('/api/sync/provider')->assertOk();
        $this->assertSame(64.0, Asset::where('provider_device_id', '101')->first()->specifications->ram_gb);
        $this->postJson('/api/sync')->assertOk();
        $this->assertSame(16.0, Asset::where('provider_device_id', '101')->first()->specifications->ram_gb);
    }

    public function test_restore_fences_provider_removal_even_when_its_queue_message_is_pending(): void
    {
        Queue::fake();
        $this->sync();
        $this->patchJson('/api/provider/jamf/devices/101', ['specifications' => ['ram_gb' => 32]])->assertOk();
        $old = EventInbox::first();
        $this->deleteJson('/api/provider/jamf/devices/101')->assertOk();
        $asset = Asset::where('provider_device_id', '101')->first();
        $this->deleteJson('/api/assets/'.$asset->id)->assertNoContent();
        $this->postJson('/api/restorations')->assertOk();
        $this->assertDatabaseHas('restore_tasks', ['provider_device_id' => '101', 'status' => 'upstream_missing']);
        app(EventProcessor::class)->process($old->id);
        $this->assertSame('ignored_stale', $old->fresh()->outcome);
        $this->assertDatabaseMissing('assets', ['provider_device_id' => '101']);
    }
}
