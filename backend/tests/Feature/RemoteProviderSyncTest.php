<?php

namespace Tests\Feature;

use App\Contracts\DeviceProvider;
use App\Data\DeviceEvent;
use App\Models\Asset;
use App\Models\DeviceEventState;
use App\Services\AssetDeletionService;
use App\Services\EventProcessor;
use App\Services\EventReceiver;
use App\Services\JamfSyncService;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\InventoryTestCase;

final class RemoteProviderSyncTest extends InventoryTestCase
{
    public function createApplication()
    {
        $previous = [];
        foreach (['PROVIDER_MODE' => 'remote', 'APP_ROLE' => 'inventory'] as $key => $value) {
            $previous[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
            putenv("$key=$value");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        try {
            return parent::createApplication();
        } finally {
            foreach ($previous as $key => [$process, $environment, $server]) {
                $process === false ? putenv($key) : putenv("$key=$process");
                if ($environment === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $environment;
                }
                if ($server === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $server;
                }
            }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['mdm.provider_mode' => 'remote', 'mdm.provider_url' => 'http://provider.test/api/provider']);
        Queue::fake();
        Http::preventStrayRequests();
    }

    private function envelope(int $version = 1, int $generation = 1, string $type = 'device.changed', float $ram = 16): array
    {
        $record = app(DeviceProvider::class)->findByProviderId('101');
        $device = DeviceEvent::fromRecord($record);
        $device['specifications']['ram_gb'] = $ram;

        return ['event_id' => "remote-$version-$generation-$type", 'provider_device_id' => '101', 'version' => $version,
            'producer_generation' => $generation, 'type' => $type, 'device' => $device];
    }

    private function snapshot(array $events, int $generation): array
    {
        return ['generation' => $generation, 'devices' => $events];
    }

    private function assertNoProducerWrites(): void
    {
        foreach (['mock_provider_devices', 'simulated_provider_devices', 'producer_outboxes'] as $table) {
            if (Schema::hasTable($table)) {
                $this->assertSame(0, DB::table($table)->count(), "$table must not be written by inventory");
            }
        }
    }

    public function test_provider_lab_create_update_and_remove_return_actual_sync_counters(): void
    {
        $state = null;
        $version = 0;
        $generation = 0;
        Http::fake(function (ClientRequest $request) use (&$state, &$version, &$generation) {
            $url = $request->url();
            if ($request->method() === 'POST' && str_ends_with($url, '/api/provider/devices')) {
                $device = $request->data();
                $version = $generation = 1;
                $state = [
                    'event_id' => 'lab-1',
                    'provider_device_id' => $device['provider_device_id'],
                    'version' => $version,
                    'producer_generation' => $generation,
                    'type' => 'device.added',
                    'device' => $device,
                ];
            } elseif ($request->method() === 'PATCH' && str_ends_with($url, '/api/provider/devices/lab-1')) {
                $version++;
                $generation++;
                $state['device'] = array_replace_recursive($state['device'], $request->data());
                $state = [
                    'event_id' => 'lab-'.$generation,
                    'provider_device_id' => 'lab-1',
                    'version' => $version,
                    'producer_generation' => $generation,
                    'type' => 'device.changed',
                    'device' => $state['device'],
                ];
            } elseif ($request->method() === 'DELETE' && str_ends_with($url, '/api/provider/devices/lab-1')) {
                $version++;
                $generation++;
                $state = [
                    'event_id' => 'lab-'.$generation,
                    'provider_device_id' => 'lab-1',
                    'version' => $version,
                    'producer_generation' => $generation,
                    'type' => 'device.removed',
                    'device' => $state['device'],
                ];
            } elseif ($request->method() === 'GET' && str_ends_with($url, '/api/provider/snapshot')) {
                return Http::response(['generation' => $generation, 'devices' => [$state]]);
            } else {
                return Http::response(['message' => 'Unexpected provider request.'], 500);
            }

            return Http::response([
                'generation' => $generation,
                'emitted' => 1,
                'event_ids' => [$state['event_id']],
                'device' => $state,
            ]);
        });

        $created = $this->postJson('/api/demo/provider-devices', [
            'provider_device_id' => 'lab-1',
            'serial_code' => 'LAB-SERIAL-1',
            'device_name' => 'Provider Lab MacBook',
            'employee_email' => 'lab.employee@company.test',
            'employee_name' => 'Lab Employee',
            'ram_gb' => 16,
        ])->assertOk()->json();
        $this->assertSame(1, $created['sync']['counters']['created_assets']);
        $this->assertSame('device.added', $created['provider_event']);
        $this->assertDatabaseHas('assets', ['provider_device_id' => 'lab-1', 'device_name' => 'Provider Lab MacBook']);

        $updated = $this->patchJson('/api/demo/provider-devices/lab-1', [
            'device_name' => 'Provider Lab MacBook Updated',
            'ram_gb' => 32,
        ])->assertOk()->json();
        $this->assertSame(1, $updated['sync']['counters']['updated_assets']);
        $this->assertSame('device.changed', $updated['provider_event']);
        $this->assertSame(32.0, (float) Asset::where('provider_device_id', 'lab-1')->firstOrFail()->specifications->ram_gb);

        $removed = $this->deleteJson('/api/demo/provider-devices/lab-1')->assertOk()->json();
        $this->assertSame(1, $removed['sync']['counters']['removed_assets']);
        $this->assertSame('device.removed', $removed['provider_event']);
        $this->assertDatabaseMissing('assets', ['provider_device_id' => 'lab-1']);
        $this->assertNoProducerWrites();
    }

    public function test_full_import_crosses_http_boundary_and_applies_returned_provider_versions(): void
    {
        $event = $this->envelope(7, 12);
        Http::fake(['provider.test/api/provider/import' => Http::response($this->snapshot([$event], 12))]);
        $run = app(JamfSyncService::class)->full();
        $this->assertSame('succeeded', $run->status);
        $this->assertDatabaseHas('assets', ['provider_device_id' => '101']);
        $this->assertDatabaseHas('device_event_states', ['provider_device_id' => '101', 'version' => 7, 'producer_generation' => 12]);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'http://provider.test/api/provider/import'
            && $request['totalCount'] === 7 && count($request['results']) === 7);
        Http::assertSentCount(1);
        $this->assertNoProducerWrites();
    }

    public function test_targeted_restoration_fences_older_queued_event_without_snapshot_or_file_read(): void
    {
        $base = $this->envelope();
        $latest = $this->envelope(3, 3, ram: 64);
        Http::fake([
            'provider.test/api/provider/import' => Http::response($this->snapshot([$base], 1)),
            'provider.test/api/provider/devices/101' => Http::response($latest),
        ]);
        app(JamfSyncService::class)->full();
        $old = app(EventReceiver::class)->accept($this->envelope(2, 2, ram: 32));
        app(AssetDeletionService::class)->delete(Asset::firstOrFail()->id);
        file_put_contents($this->fixture, 'not-json');
        app(JamfSyncService::class)->restore();
        $this->assertSame(64.0, Asset::firstOrFail()->specifications->ram_gb);
        app(EventProcessor::class)->process($old->id);
        $this->assertSame('ignored_stale', $old->fresh()->outcome);
        $this->assertSame(64.0, Asset::firstOrFail()->specifications->ram_gb);
        $this->assertDatabaseHas('restore_tasks', ['provider_device_id' => '101', 'status' => 'restored']);
        Http::assertSentCount(2);
        $this->assertNoProducerWrites();
    }

    public function test_provider_reconciliation_tombstone_blocks_delayed_assigned_event(): void
    {
        $base = $this->envelope();
        $removed = $this->envelope(3, 3, 'device.removed');
        Http::fake([
            'provider.test/api/provider/import' => Http::response($this->snapshot([$base], 1)),
            'provider.test/api/provider/snapshot' => Http::response($this->snapshot([$removed], 3)),
        ]);
        app(JamfSyncService::class)->full();
        $old = app(EventReceiver::class)->accept($this->envelope(2, 2));
        app(JamfSyncService::class)->reconcileProvider();
        $this->assertDatabaseCount('assets', 0);
        app(EventProcessor::class)->process($old->id);
        $this->assertSame('ignored_stale', $old->fresh()->outcome);
        $this->assertDatabaseCount('assets', 0);
        $this->assertDatabaseHas('device_event_states', ['provider_device_id' => '101', 'state' => 'removed', 'version' => 3]);
        $this->assertNoProducerWrites();
    }

    public function test_stale_remote_snapshot_fails_without_erasing_current_inventory(): void
    {
        Http::fake([
            'provider.test/api/provider/import' => Http::response($this->snapshot([$this->envelope(4, 9, ram: 64)], 9)),
            'provider.test/api/provider/snapshot' => Http::response($this->snapshot([], 8)),
        ]);
        app(JamfSyncService::class)->full();
        try {
            app(JamfSyncService::class)->reconcileProvider();
            $this->fail('Stale complete snapshot must be rejected.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('older', $error->getMessage());
        }
        $this->assertDatabaseCount('assets', 1);
        $this->assertSame(64.0, Asset::firstOrFail()->specifications->ram_gb);
        $this->assertSame(9, DeviceEventState::firstOrFail()->producer_generation);
    }

    public function test_same_version_conflicting_remote_state_is_rejected_atomically(): void
    {
        Http::fake([
            'provider.test/api/provider/import' => Http::response($this->snapshot([$this->envelope(2, 2)], 2)),
            'provider.test/api/provider/snapshot' => Http::response($this->snapshot([$this->envelope(2, 2, ram: 64)], 2)),
        ]);
        app(JamfSyncService::class)->full();
        try {
            app(JamfSyncService::class)->reconcileProvider();
            $this->fail('Conflicting payload at the same provider version must be rejected.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('conflicts', $error->getMessage());
        }
        $this->assertSame(16.0, Asset::firstOrFail()->specifications->ram_gb);
        $this->assertSame(2, DeviceEventState::firstOrFail()->version);
    }

    public function test_targeted_response_cannot_substitute_a_different_provider_identity(): void
    {
        $base = $this->envelope();
        $wrongIdentity = $this->envelope(2, 2);
        $wrongIdentity['provider_device_id'] = $wrongIdentity['device']['provider_device_id'] = '999';
        Http::fake([
            'provider.test/api/provider/import' => Http::response($this->snapshot([$base], 1)),
            'provider.test/api/provider/devices/101' => Http::response($wrongIdentity),
        ]);
        app(JamfSyncService::class)->full();
        app(AssetDeletionService::class)->delete(Asset::firstOrFail()->id);
        try {
            app(JamfSyncService::class)->restore();
            $this->fail('Targeted lookup must preserve the requested provider identity.');
        } catch (\RuntimeException $error) {
            $this->assertNotEmpty($error->getMessage());
        }
        $this->assertDatabaseCount('assets', 0);
        $this->assertDatabaseHas('restore_tasks', ['provider_device_id' => '101', 'status' => 'pending']);
        $this->assertDatabaseMissing('device_event_states', ['provider_device_id' => '999']);
    }

    public function test_inventory_role_does_not_expose_provider_mutation_routes(): void
    {
        $this->postJson('/api/provider/jamf/seed')->assertNotFound();
        $this->patchJson('/api/provider/jamf/devices/101', ['email' => null])->assertNotFound();
        $this->postJson('/api/provider/import', $this->source)->assertNotFound();
        Http::assertNothingSent();
        $this->assertDatabaseCount('assets', 0);
    }
}
