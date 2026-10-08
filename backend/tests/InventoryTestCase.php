<?php

namespace Tests;

use App\Services\JamfSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;

abstract class InventoryTestCase extends TestCase
{
    use RefreshDatabase;

    protected string $fixture;

    protected array $source;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mdm.provider_mode' => 'local']);
        $this->fixture = tempnam(sys_get_temp_dir(), 'mdm');
        $this->source = json_decode(file_get_contents(base_path('../files/api-mock-response.json')), true);
        config(['mdm.mock_path' => $this->fixture]);
        $this->write();
    }

    protected function tearDown(): void
    {
        unlink($this->fixture);
        parent::tearDown();
    }

    protected function write(): void
    {
        file_put_contents($this->fixture, json_encode($this->source));
    }

    protected function sync()
    {
        return app(JamfSyncService::class)->full();
    }
}
