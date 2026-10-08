<?php

namespace App\Services;

use App\Contracts\Persistence\DemoStateRepository;
use App\Contracts\RemoteProviderGateway;

final class DemoControlService
{
    public function __construct(private RemoteProviderGateway $provider, private DemoStateRepository $state) {}

    public function reset(): array
    {
        $provider = $this->provider->resetDemo();
        $this->state->resetInventory();

        return ['provider_devices' => $provider['active_devices'] ?? 0];
    }

    public function simulateKafkaFailure(): array
    {
        return $this->provider->simulateKafkaFailure();
    }
}
