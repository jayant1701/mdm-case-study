<?php

namespace Tests\Feature;

use App\Jobs\ProcessDeviceEvent;
use App\Models\Asset;
use App\Models\EventInbox;
use App\Models\SyncRun;
use App\Services\EventReceiver;
use App\Services\SyncCoordinator;
use Illuminate\Support\Facades\Queue;
use Tests\InventoryTestCase;

class EventSyncTest extends InventoryTestCase
{
    private function notification(string $event = 'demo-1', string $device = '101', string $type = 'device.changed'): array
    {
        return ['event_id' => $event, 'provider_device_id' => $device, 'type' => $type];
    }

    public function test_receipt_is_durable_and_duplicate_event_is_idempotent(): void
    {
        Queue::fake();
        $this->sync();
        $this->postJson('/api/events/jamf', $this->notification())->assertStatus(202);
        $this->postJson('/api/events/jamf', $this->notification())->assertStatus(202);
        $this->assertDatabaseCount('event_inboxes', 1);
        $inbox = EventInbox::first();
        $job = new ProcessDeviceEvent($inbox->id);
        $job->handle(app(SyncCoordinator::class));
        $job->handle(app(SyncCoordinator::class));
        $this->assertSame(1, $inbox->fresh()->attempts);
        $this->assertSame('succeeded', $inbox->fresh()->status);
        $this->assertDatabaseCount('assets', 7);
        $this->assertSame(1, SyncRun::where('mode', 'event')->count());
    }

    public function test_conflicting_event_id_and_invalid_schema_are_rejected(): void
    {
        Queue::fake();
        $this->postJson('/api/events/jamf', $this->notification())->assertStatus(202);
        $this->postJson('/api/events/jamf', $this->notification(device: '102'))->assertStatus(409);
        $this->postJson('/api/events/jamf', ['event_id' => 'bad'])->assertUnprocessable();
    }

    public function test_event_reads_latest_state_and_does_not_remove_unrelated_devices(): void
    {
        Queue::fake();
        $this->sync();
        $this->postJson('/api/events/jamf', $this->notification(type: 'device.removed'));
        $this->source['results'][0]['hardware']['totalRamMegabytes'] = 65536;
        array_pop($this->source['results']);
        $this->source['totalCount'] = 6;
        $this->write();
        (new ProcessDeviceEvent(EventInbox::first()->id))->handle(app(SyncCoordinator::class));
        $this->assertDatabaseCount('assets', 7);
        $this->assertSame(64.0, Asset::where('provider_device_id', '101')->first()->specifications->ram_gb);
    }

    public function test_missing_device_event_removes_only_that_asset(): void
    {
        Queue::fake();
        $this->sync();
        $this->postJson('/api/events/jamf', $this->notification());
        array_shift($this->source['results']);
        $this->source['totalCount'] = 6;
        $this->write();
        (new ProcessDeviceEvent(EventInbox::first()->id))->handle(app(SyncCoordinator::class));
        $this->assertDatabaseCount('assets', 6);
        $this->assertDatabaseMissing('assets', ['provider_device_id' => '101']);
    }

    public function test_failed_event_remains_visible_and_can_be_retried(): void
    {
        Queue::fake();
        $this->sync();
        $this->postJson('/api/events/jamf', $this->notification());
        $inbox = EventInbox::first();
        file_put_contents($this->fixture, 'broken');
        try {
            (new ProcessDeviceEvent($inbox->id))->handle(app(SyncCoordinator::class));
            $this->fail('Expected failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('failed', $inbox->fresh()->status);
            $this->assertSame(1, $inbox->fresh()->attempts);
            $this->assertNotNull($inbox->fresh()->last_error);
            $this->assertDatabaseCount('assets', 7);
            $this->assertSame('failed', SyncRun::latest('id')->first()->status);
        }
        $this->write();
        $this->postJson('/api/events/'.$inbox->id.'/retry')->assertStatus(202);
        (new ProcessDeviceEvent($inbox->id))->handle(app(SyncCoordinator::class));
        $this->assertSame('succeeded', $inbox->fresh()->status);
        $this->assertSame(2, $inbox->fresh()->attempts);
    }

    public function test_pending_inbox_recovery_closes_dispatch_gap(): void
    {
        Queue::fake();
        $inbox = EventInbox::create($this->notification() + ['provider' => 'jamf', 'payload' => $this->notification(), 'status' => 'pending']);
        $this->assertSame(1, app(EventReceiver::class)->recover());
        Queue::assertPushed(ProcessDeviceEvent::class, fn ($job) => $job->inboxId === $inbox->id);
    }
}
