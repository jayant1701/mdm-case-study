<?php

namespace Tests\Feature;

use App\Contracts\EventPublisher;
use App\Contracts\Persistence\ProviderOutboxRepository;
use App\Http\Controllers\IndependentProviderController;
use App\Models\ProducerOutbox;
use App\Services\IndependentProviderService;
use App\Services\ProducerOutboxPublisher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IndependentProviderTest extends TestCase
{
    public function test_outbox_activity_and_manual_retry_report_delivery_separately(): void
    {
        $this->service()->import($this->source);
        $outbox = app(ProviderOutboxRepository::class);
        $controller = app(IndependentProviderController::class);
        $activity = $controller->outbox($outbox);
        $this->assertSame(7, $activity['pending']);
        $this->assertSame(['pending'], array_keys($activity));
        $this->app->instance(EventPublisher::class, new class implements EventPublisher
        {
            public function publish(array $payload): void
            {
                throw new \RuntimeException('broker unavailable');
            }
        });
        $failed = $controller->retryOutbox(app(ProducerOutboxPublisher::class), $outbox);
        $this->assertSame(1, $failed['failed']);
        $this->assertSame(7, $failed['pending']);
        $this->assertStringContainsString('broker unavailable', ProducerOutbox::where('status', 'pending')->first()->last_error);
        $this->app->instance(EventPublisher::class, new class implements EventPublisher
        {
            public function publish(array $payload): void {}
        });
        $delivered = $controller->retryOutbox(app(ProducerOutboxPublisher::class), $outbox);
        $this->assertSame(7, $delivered['published']);
        $this->assertSame(0, $delivered['pending']);
        $again = $controller->retryOutbox(app(ProducerOutboxPublisher::class), $outbox);
        $this->assertSame(0, $again['published']);
    }

    private array $source;

    protected function setUp(): void
    {
        parent::setUp();
        DB::purge();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        $this->artisan('migrate', ['--path' => 'database/provider-migrations', '--force' => true])->assertExitCode(0);
        $this->source = json_decode(file_get_contents(base_path('../files/api-mock-response.json')), true);
    }

    protected function tearDown(): void
    {
        DB::disconnect();
        parent::tearDown();
    }

    private function service(): IndependentProviderService
    {
        return app(IndependentProviderService::class);
    }

    public function test_import_owns_only_provider_tables_and_unchanged_import_has_no_notifications(): void
    {
        $result = $this->service()->import($this->source);
        $this->assertSame(7, $result['emitted']);
        $this->assertCount(7, $result['devices']);
        $this->assertFalse(Schema::hasTable('assets'));
        $this->assertFalse(Schema::hasTable('event_inboxes'));
        $again = $this->service()->import($this->source);
        $this->assertSame(0, $again['emitted']);
        $this->assertSame(1, $again['generation']);
        $this->assertDatabaseCount('producer_outbox', 7);
    }

    public function test_heartbeat_metadata_persists_without_versions_or_notifications(): void
    {
        $this->service()->import($this->source);
        $this->source['results'][0]['general']['reportDate'] = '2026-10-08T12:00:00Z';
        $this->source['results'][0]['general']['lastContactTime'] = '2026-10-08T12:00:00Z';
        $result = $this->service()->import($this->source);
        $this->assertSame(0, $result['emitted']);
        $current = $this->service()->current('101');
        $this->assertSame(1, $current['version']);
        $this->assertSame('2026-10-08 12:00:00', $current['device']['asset']['report_date']);
    }

    public function test_provider_mutation_and_outbox_rollback_together_on_save_failure(): void
    {
        $this->service()->import($this->source);
        DB::statement("CREATE TRIGGER reject_provider_event BEFORE INSERT ON producer_outbox BEGIN SELECT RAISE(ABORT, 'outbox unavailable'); END");
        try {
            $this->service()->patch('101', ['specifications' => ['ram_gb' => 64]]);
            $this->fail('Expected outbox failure');
        } catch (QueryException $error) {
            $this->assertStringContainsString('outbox unavailable', $error->getMessage());
        }
        $this->assertSame(16.0, (float) $this->service()->current('101')['device']['specifications']['ram_gb']);
        $this->assertSame(1, $this->service()->current('101')['version']);
        $this->assertDatabaseCount('producer_outbox', 7);
        $this->assertSame(1, $this->service()->snapshot()['generation']);
    }

    public function test_tombstone_and_recreation_preserve_provider_identity_and_versions(): void
    {
        $this->service()->import($this->source);
        $before = $this->service()->current('101');
        $removed = $this->service()->delete('101');
        $this->assertSame('device.removed', $removed['device']['type']);
        $this->assertSame(2, $removed['device']['version']);
        $this->assertCount(7, $this->service()->snapshot()['devices']);
        $created = $this->service()->create($before['device']);
        $this->assertSame(3, $created['device']['version']);
        $this->assertSame('device.changed', $created['device']['type']);
        $this->assertDatabaseCount('provider_devices', 7);
    }

    public function test_failed_kafka_delivery_retains_outbox_for_acknowledged_retry(): void
    {
        $this->service()->import($this->source);
        $failed = new class implements EventPublisher
        {
            public function publish(array $payload): void
            {
                throw new \RuntimeException('broker unavailable');
            }
        };
        $result = (new ProducerOutboxPublisher($failed))->drain();
        $this->assertSame(1, $result['failed']);
        $row = ProducerOutbox::first();
        $this->assertSame('pending', $row->status);
        $this->assertSame(1, $row->attempts);
        $this->assertStringContainsString('broker unavailable', $row->last_error);
        $success = new class implements EventPublisher
        {
            public array $messages = [];

            public function publish(array $payload): void
            {
                $this->messages[] = $payload;
            }
        };
        $result = (new ProducerOutboxPublisher($success))->drain(100, true);
        $this->assertSame(7, $result['published']);
        $this->assertCount(7, $success->messages);
        $this->assertSame('published', $row->fresh()->status);
    }

    public function test_demo_failure_stays_visible_until_manual_recovery(): void
    {
        $this->service()->import($this->source);
        ProducerOutbox::query()->update(['status' => 'published', 'published_at' => now()]);

        $failure = $this->service()->simulateKafkaFailure();

        $this->assertSame(1, $failure['affected']);
        $this->assertSame(1, $failure['pending']);
        $pending = ProducerOutbox::where('status', 'pending')->firstOrFail();
        $this->assertSame(1, $pending->attempts);
        $this->assertStringContainsString('Simulated Kafka', $pending->last_error);
        $this->assertTrue($pending->next_attempt_at->isFuture());

        $success = new class implements EventPublisher
        {
            public function publish(array $payload): void {}
        };
        $publisher = new ProducerOutboxPublisher($success);
        $this->assertSame(0, $publisher->drain()['published']);
        $this->assertSame(1, $publisher->drain(10, true)['published']);
        $this->assertSame('published', $pending->fresh()->status);
    }

    public function test_demo_reset_reloads_fixture_without_queuing_notifications(): void
    {
        $this->service()->import($this->source);
        $this->service()->patch('101', ['specifications' => ['ram_gb' => 64]]);
        $generation = $this->service()->snapshot()['generation'];

        $result = $this->service()->resetDemo();

        $this->assertCount(7, $result['devices']);
        $this->assertSame(7, $result['active_devices']);
        $this->assertSame(0, $result['pending']);
        $this->assertGreaterThan($generation, $result['generation']);
        $this->assertSame(16.0, (float) $this->service()->current('101')['device']['specifications']['ram_gb']);
        $this->assertDatabaseCount('producer_outbox', 0);
    }

    public function test_partial_patch_and_shared_employee_updates_emit_only_affected_devices(): void
    {
        $this->service()->import($this->source);
        $result = $this->service()->patch('101', ['employee' => ['name' => 'Alex Updated']]);
        $this->assertSame(2, $result['emitted']);
        $this->assertSame('Alex Updated', $this->service()->current('103')['device']['employee']['name']);
        $this->assertSame(16.0, (float) $this->service()->current('101')['device']['specifications']['ram_gb']);
        $result = $this->service()->patch('101', ['specifications' => ['ram_gb' => 32]]);
        $this->assertSame(1, $result['emitted']);
        $this->assertSame(32.0, (float) $result['device']['device']['specifications']['ram_gb']);
    }
}
