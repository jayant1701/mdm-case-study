<?php

namespace Tests\Feature;

use App\Contracts\DeviceProvider;
use App\Data\DeviceEvent;
use App\Jobs\ProcessDeviceEvent;
use App\Models\EventInbox;
use App\Services\KafkaNotificationReceiver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\InventoryTestCase;

final class KafkaNotificationReceiverTest extends InventoryTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function payload(): array
    {
        return ['event_id' => 'kafka-test-101', 'provider_device_id' => '101', 'version' => 1,
            'producer_generation' => 1, 'type' => 'device.added',
            'device' => DeviceEvent::fromRecord(app(DeviceProvider::class)->findByProviderId('101'))];
    }

    private function receive(string $payload, int $offset = 1): void
    {
        app(KafkaNotificationReceiver::class)->receive($payload, 'jamf.devices', 0, $offset);
    }

    public function test_valid_event_is_durable_before_the_caller_can_acknowledge(): void
    {
        $this->receive(json_encode($this->payload(), JSON_THROW_ON_ERROR));
        $this->assertDatabaseCount('event_inboxes', 1);
        $this->assertSame('pending', EventInbox::firstOrFail()->status);
        $this->assertDatabaseCount('rejected_notifications', 0);
        $this->assertDatabaseCount('assets', 0);
        Queue::assertPushed(ProcessDeviceEvent::class);
    }

    public function test_replayed_offset_and_same_event_at_another_offset_create_one_inbox(): void
    {
        $payload = json_encode($this->payload(), JSON_THROW_ON_ERROR);
        $this->receive($payload);
        $this->receive($payload);
        $this->receive($payload, 2);
        $this->assertDatabaseCount('event_inboxes', 1);
        $this->assertDatabaseCount('rejected_notifications', 0);
    }

    public function test_invalid_json_is_durably_quarantined_once_per_offset(): void
    {
        $this->receive('{bad-json', 7);
        $this->receive('{bad-json', 7);
        $this->assertDatabaseCount('rejected_notifications', 1);
        $this->assertDatabaseHas('rejected_notifications', ['topic' => 'jamf.devices', 'partition' => 0,
            'offset' => 7, 'payload' => '{bad-json']);
        $this->assertDatabaseCount('event_inboxes', 0);
    }

    public function test_invalid_event_schema_is_quarantined_without_dispatch(): void
    {
        $this->receive(json_encode(['event_id' => 'missing-state'], JSON_THROW_ON_ERROR), 8);
        $this->assertDatabaseCount('rejected_notifications', 1);
        $this->assertNotEmpty(DB::table('rejected_notifications')->value('error'));
        $this->assertDatabaseCount('event_inboxes', 0);
        Queue::assertNothingPushed();
    }

    public function test_canonical_identity_conflict_is_quarantined_and_original_inbox_preserved(): void
    {
        $original = $this->payload();
        $this->receive(json_encode($original, JSON_THROW_ON_ERROR), 10);
        $conflict = $original;
        $conflict['device']['specifications']['ram_gb'] = 64;
        $this->receive(json_encode($conflict, JSON_THROW_ON_ERROR), 11);
        $this->assertDatabaseCount('event_inboxes', 1);
        $this->assertDatabaseCount('rejected_notifications', 1);
        $this->assertDatabaseHas('rejected_notifications', ['offset' => 11]);
        $this->assertSame(16.0, (float) EventInbox::firstOrFail()->payload['device']['specifications']['ram_gb']);
    }

    public function test_inbox_database_failure_propagates_instead_of_allowing_acknowledgement(): void
    {
        Schema::drop('event_inboxes');
        $this->expectException(QueryException::class);
        try {
            $this->receive(json_encode($this->payload(), JSON_THROW_ON_ERROR));
        } finally {
            $this->assertDatabaseCount('rejected_notifications', 0);
            Queue::assertNothingPushed();
        }
    }

    public function test_quarantine_database_failure_also_propagates(): void
    {
        Schema::drop('rejected_notifications');
        $this->expectException(QueryException::class);
        $this->receive('invalid-json');
    }
}
