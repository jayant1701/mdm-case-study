<?php

namespace Tests\Feature;

use App\Contracts\DeviceProvider;
use App\Contracts\Persistence\EventInboxRepository;
use App\Data\DeviceEvent;
use App\Jobs\ProcessDeviceEvent;
use App\Models\Asset;
use App\Models\DeviceEventState;
use App\Models\Employee;
use App\Models\EventInbox;
use App\Models\SimulatedProviderDevice;
use App\Services\DeviceEventSimulator;
use App\Services\EventProcessor;
use App\Services\EventReceiver;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\InventoryTestCase;

class EventSyncTest extends InventoryTestCase
{
    private function notification(string $event = 'demo-1', string $id = '101', int $version = 2, int $generation = 2, string $type = 'device.changed'): array
    {
        $record = app(DeviceProvider::class)->findByProviderId($id);

        return ['event_id' => $event, 'provider_device_id' => $id, 'version' => $version, 'producer_generation' => $generation, 'type' => $type, 'device' => DeviceEvent::fromRecord($record)];
    }

    private function process(EventInbox $event): void
    {
        (new ProcessDeviceEvent($event->id))->handle(app(EventProcessor::class));
    }

    public function test_durable_receipt_duplicate_and_conflicting_identity_or_version(): void
    {
        Queue::fake();
        $this->sync();
        $payload = $this->notification();
        $this->postJson('/api/events/jamf', $payload)->assertStatus(202);
        $this->postJson('/api/events/jamf', $payload)->assertStatus(202);
        $this->assertDatabaseCount('event_inboxes', 1);
        $changed = $payload;
        $changed['device']['specifications']['ram_gb'] = 64;
        $this->postJson('/api/events/jamf', $changed)->assertStatus(409);
        $changed['event_id'] = 'other-id';
        $this->postJson('/api/events/jamf', $changed)->assertStatus(409);
        $this->postJson('/api/events/jamf', ['event_id' => 'bad'])->assertUnprocessable();
        $event = EventInbox::first();
        $this->process($event);
        $this->process($event);
        $this->assertSame(1, $event->fresh()->attempts);
        $this->assertSame('succeeded', $event->fresh()->status);
    }

    public function test_worker_uses_stored_payload_with_zero_provider_reads_even_when_file_breaks(): void
    {
        Queue::fake();
        $this->sync();
        $payload = $this->notification();
        $payload['device']['specifications']['ram_gb'] = 64;
        app(EventReceiver::class)->accept($payload);
        file_put_contents($this->fixture, 'broken');
        $provider = Mockery::mock(DeviceProvider::class);
        $provider->shouldNotReceive('snapshot');
        $provider->shouldNotReceive('findByProviderId');
        $this->app->instance(DeviceProvider::class, $provider);
        $this->process(EventInbox::first());
        $this->assertSame(64.0, Asset::where('provider_device_id', '101')->first()->specifications->ram_gb);
        $this->assertDatabaseCount('assets', 7);
    }

    public function test_simulator_ignores_last_contact_but_emits_report_date_and_hardware_changes(): void
    {
        Queue::fake();
        $first = app(DeviceEventSimulator::class)->simulate();
        $this->assertSame(7, $first['emitted']);
        $this->assertSame(0, app(DeviceEventSimulator::class)->simulate()['emitted']);
        foreach ($this->source['results'] as &$row) {
            $row['general']['lastContactTime'] = '2026-10-08T12:00:00Z';
        }unset($row);
        $this->write();
        $this->assertSame(0, app(DeviceEventSimulator::class)->simulate()['emitted']);
        $this->source['results'][0]['general']['reportDate'] = '2026-10-08T12:00:00Z';
        $this->write();
        $reportDateChange = app(DeviceEventSimulator::class)->simulate();
        $this->assertSame(1, $reportDateChange['emitted']);
        $this->assertSame(2, EventInbox::latest('id')->first()->version);
        $this->source['results'][0]['hardware']['totalRamMegabytes'] = 65536;
        $this->write();
        $next = app(DeviceEventSimulator::class)->simulate();
        $this->assertSame(1, $next['emitted']);
        $event = EventInbox::latest('id')->first();
        $this->assertSame(3, $event->version);
        $this->assertSame('2026-10-08 12:00:00', $event->payload['device']['asset']['report_date']);
        $this->process($event);
        $this->assertSame(64.0, Asset::first()->specifications->ram_gb);
    }

    public function test_removal_and_unassignment_tombstones_block_stale_resurrection(): void
    {
        Queue::fake();
        $this->sync();
        $old = $this->notification(event: 'old', version: 2, generation: 2);
        $remove = $old;
        $remove['event_id'] = 'remove';
        $remove['version'] = 3;
        $remove['producer_generation'] = 3;
        $remove['type'] = 'device.removed';
        $removed = app(EventReceiver::class)->accept($remove);
        $this->process($removed);
        $this->assertDatabaseMissing('assets', ['provider_device_id' => '101']);
        $late = app(EventReceiver::class)->accept($old);
        $this->process($late);
        $this->assertSame('ignored_stale', $late->fresh()->outcome);
        $this->assertDatabaseMissing('assets', ['provider_device_id' => '101']);
        $new = $old;
        $new['event_id'] = 'readd';
        $new['version'] = 4;
        $new['producer_generation'] = 4;
        $new['type'] = 'device.added';
        $this->process(app(EventReceiver::class)->accept($new));
        $this->assertDatabaseHas('assets', ['provider_device_id' => '101']);
        $unassigned = $new;
        $unassigned['event_id'] = 'unassigned';
        $unassigned['version'] = 5;
        $unassigned['producer_generation'] = 5;
        $unassigned['type'] = 'device.unassigned';
        $unassigned['device']['email'] = null;
        $this->process(app(EventReceiver::class)->accept($unassigned));
        $this->assertDatabaseMissing('assets', ['provider_device_id' => '101']);
        $this->assertSame('unassigned', DeviceEventState::where('provider_device_id', '101')->first()->state);
    }

    public function test_equal_applied_version_conflict_fails_without_writes(): void
    {
        Queue::fake();
        $this->sync();
        $payload = $this->notification(version: 1, generation: 1);
        $payload['device']['specifications']['ram_gb'] = 64;
        $inbox = app(EventReceiver::class)->accept($payload);
        try {
            $this->process($inbox);
            $this->fail('Expected conflict.');
        } catch (ConflictHttpException $error) {
            $this->assertStringContainsString('Conflicting payload', $error->getMessage());
        }
        $this->assertSame('retrying', $inbox->fresh()->status);
        (new ProcessDeviceEvent($inbox->id))->failed(new ConflictHttpException('Conflicting payload at an applied device version.'));
        $this->assertSame('failed', $inbox->fresh()->status);
        $failure = $this->getJson('/api/event-failures')->assertOk()->json('data.0');
        $this->assertSame('Alex’s MacBook Pro', $failure['device_name']);
        $this->assertSame('C02DL0XYZQ6N', $failure['serial_code']);
        $this->assertSame('Alex Smith', $failure['employee_name']);
        $this->assertSame('alex.smith@company.test', $failure['employee_email']);
        $this->assertArrayNotHasKey('payload', $failure);
        $this->assertArrayNotHasKey('last_error', $failure);
        $this->assertSame(16.0, Asset::where('provider_device_id', '101')->first()->specifications->ram_gb);
    }

    public function test_full_sync_fences_already_queued_old_payload(): void
    {
        Queue::fake();
        $this->sync();
        $this->source['results'][0]['hardware']['totalRamMegabytes'] = 32768;
        $this->write();
        $this->assertSame(1, app(DeviceEventSimulator::class)->simulate()['emitted']);
        $queued = EventInbox::first();
        $this->source['results'][0]['hardware']['totalRamMegabytes'] = 65536;
        $this->write();
        $this->sync();
        $this->process($queued);
        $this->assertSame('ignored_stale', $queued->fresh()->outcome);
        $this->assertSame(64.0, Asset::where('provider_device_id', '101')->first()->specifications->ram_gb);
    }

    public function test_simulator_emits_removal_unassignment_and_readdition(): void
    {
        Queue::fake();
        $this->sync();
        $this->assertSame(0, app(DeviceEventSimulator::class)->simulate()['emitted']);
        $this->source['results'][0]['userAndLocation']['email'] = null;
        $saved = array_pop($this->source['results']);
        $this->source['totalCount'] = 6;
        $this->write();
        $this->assertSame(2, app(DeviceEventSimulator::class)->simulate()['emitted']);
        $this->assertDatabaseHas('event_inboxes', ['provider_device_id' => '101', 'type' => 'device.unassigned']);
        $this->assertDatabaseHas('event_inboxes', ['provider_device_id' => '107', 'type' => 'device.removed']);
        foreach (EventInbox::all() as $event) {
            $this->process($event);
        }$this->assertDatabaseCount('assets', 5);
        $this->source['results'][] = $saved;
        $this->source['totalCount'] = 7;
        $this->write();
        $this->assertSame(1, app(DeviceEventSimulator::class)->simulate()['emitted']);
        $new = EventInbox::latest('id')->first();
        $this->assertSame('device.added', $new->type);
        $this->assertSame(3, $new->version);
    }

    public function test_shared_employee_metadata_does_not_regress_from_older_other_device(): void
    {
        Queue::fake();
        $this->sync();
        $new = $this->notification(event: 'new-name', id: '103', generation: 3);
        $new['device']['employee']['name'] = 'Latest Alex';
        $this->process(app(EventReceiver::class)->accept($new));
        $old = $this->notification(event: 'old-name', generation: 2);
        $old['device']['employee']['name'] = 'Older Alex';
        $this->process(app(EventReceiver::class)->accept($old));
        $this->assertSame('Latest Alex', Employee::where('email', 'alex.smith@company.test')->first()->name);
    }

    public function test_pending_recovery_and_invalid_snapshot_preserve_baseline(): void
    {
        Queue::fake();
        app(DeviceEventSimulator::class)->simulate();
        $this->assertSame(7, app(EventReceiver::class)->recover());
        Queue::assertPushed(ProcessDeviceEvent::class);
        $before = SimulatedProviderDevice::pluck('version', 'provider_device_id')->all();
        file_put_contents($this->fixture, 'broken');
        $this->postJson('/api/simulator/jamf')->assertStatus(422);
        $this->assertSame($before, SimulatedProviderDevice::pluck('version', 'provider_device_id')->all());
        $this->assertDatabaseCount('event_inboxes', 7);
    }

    public function test_producer_baseline_and_outbox_roll_back_together_on_injected_storage_failure(): void
    {
        Queue::fake();
        $actual = app(EventInboxRepository::class);
        $substitute = Mockery::mock(EventInboxRepository::class);
        $substitute->shouldReceive('findByEventId')->andReturnNull();
        $substitute->shouldReceive('findDeviceVersion')->andReturnNull();
        $substitute->shouldReceive('recordNotification')->once()->andReturnUsing(function ($provider, $payload) use ($actual) {
            $actual->recordNotification($provider, $payload);
            throw new \RuntimeException('Simulated outbox persistence failure.');
        });
        $this->app->instance(EventInboxRepository::class, $substitute);
        $this->postJson('/api/simulator/jamf')->assertStatus(422);
        $this->assertDatabaseCount('simulated_provider_devices', 0);
        $this->assertDatabaseCount('event_inboxes', 0);
        $this->assertDatabaseHas('simulator_generations', ['provider' => 'jamf', 'generation' => 0]);
    }

    public function test_removal_cannot_delete_an_asset_with_a_different_serial(): void
    {
        Queue::fake();
        $this->sync();
        $payload = $this->notification(type: 'device.removed');
        $payload['device']['serial_code'] = 'WRONG-SERIAL';
        $event = app(EventReceiver::class)->accept($payload);
        try {
            $this->process($event);
            $this->fail('Expected serial identity conflict.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('serial', $error->getMessage());
        }
        $this->assertDatabaseCount('assets', 7);
        $this->assertSame('retrying', $event->fresh()->status);
    }
}
