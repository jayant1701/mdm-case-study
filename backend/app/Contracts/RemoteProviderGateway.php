<?php

namespace App\Contracts;

interface RemoteProviderGateway extends TargetedDeviceProvider
{
    public function envelopesSnapshot(): array;

    public function import(array $response): array;

    public function scan(): array;

    public function outbox(): array;

    public function retryOutbox(): array;
}
