<?php

namespace Tests\Feature;

use App\Contracts\Persistence\EventInboxRepository;
use App\Contracts\Persistence\RestoreTaskRepository;
use App\Jobs\ProcessDeviceEvent;
use App\Models\Asset;
use App\Models\EventInbox;
use App\Services\EventProcessor;
use App\Services\EventReceiver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\InventoryTestCase;

final class PersistenceBoundaryTest extends InventoryTestCase
{
    public function test_substituted_restoration_repository_failure_rolls_back_local_deletion(): void
    {
        $this->sync();
        $asset = Asset::where('provider_device_id', '101')->first();
        $actual = app(RestoreTaskRepository::class);
        $substitute = Mockery::mock(RestoreTaskRepository::class);
        $substitute->shouldReceive('recordLocalDeletion')->once()->andReturnUsing(function ($record) use ($actual) {
            $actual->recordLocalDeletion($record);
            throw new RuntimeException('Simulated restoration storage failure.');
        });
        $this->app->instance(RestoreTaskRepository::class, $substitute);
        $this->deleteJson('/api/assets/'.$asset->id)->assertStatus(422);
        $this->assertDatabaseCount('assets', 7);
        $this->assertDatabaseCount('asset_attributes', 7);
        $this->assertDatabaseCount('restore_tasks', 0);
    }

    public function test_substituted_inbox_acknowledgment_failure_rolls_back_device_update(): void
    {
        Queue::fake();
        $this->sync();
        $inbox = app(EventReceiver::class)->accept(['event_id' => 'ack-failure', 'provider_device_id' => '101', 'type' => 'device.changed']);
        $this->source['results'][0]['hardware']['totalRamMegabytes'] = 65536;
        $this->write();
        $actual = app(EventInboxRepository::class);
        $substitute = Mockery::mock(EventInboxRepository::class);
        $substitute->shouldReceive('require')->once()->with($inbox->id)->andReturnUsing(fn ($id) => $actual->require($id));
        $substitute->shouldReceive('markProcessing')->once()->with($inbox->id)->andReturnUsing(fn ($id) => $actual->markProcessing($id));
        $substitute->shouldReceive('markSucceeded')->once()->with($inbox->id)->andThrow(new RuntimeException('Simulated inbox acknowledgment failure.'));
        $substitute->shouldReceive('markFailedAttempt')->once()->with($inbox->id, 'Simulated inbox acknowledgment failure.')->andReturnUsing(fn ($id, $error) => $actual->markFailedAttempt($id, $error));
        $this->app->instance(EventInboxRepository::class, $substitute);
        try {
            (new ProcessDeviceEvent($inbox->id))->handle(app(EventProcessor::class));
            $this->fail('Expected acknowledgment failure.');
        } catch (RuntimeException $error) {
            $this->assertSame('Simulated inbox acknowledgment failure.', $error->getMessage());
        }
        $this->assertSame(16.0, Asset::where('provider_device_id', '101')->first()->specifications->ram_gb);
        $this->assertSame('failed', EventInbox::find($inbox->id)->status);
        $this->assertSame(1, EventInbox::find($inbox->id)->attempts);
        $this->assertDatabaseHas('sync_runs', ['mode' => 'event', 'status' => 'failed']);
    }

    public function test_missing_provider_lock_fails_closed_before_inventory_writes(): void
    {
        DB::table('sync_locks')->where('provider', 'jamf')->delete();
        $this->postJson('/api/sync')->assertStatus(422)->assertJsonPath('message', 'No configured lock row for provider: jamf');
        $this->assertDatabaseCount('assets', 0);
        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseHas('sync_runs',['status' => 'failed']);
    }
}
