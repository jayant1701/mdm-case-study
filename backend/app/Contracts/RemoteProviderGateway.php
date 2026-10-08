<?php

namespace App\Contracts;

interface RemoteProviderGateway extends TargetedDeviceProvider
{
    public function envelopesSnapshot(): array;

    public function import(array $response): array;

    public function scan(): array;

    public function outbox(): array;

    public function retryOutbox(): array;

    public function resetDemo(): array;

    public function simulateKafkaFailure(): array;

    public function create(array $device): array;

    public function patch(string $id, array $changes): array;

    public function delete(string $id): array;
}
