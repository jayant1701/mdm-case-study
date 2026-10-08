<?php

namespace App\Contracts\Persistence;

use App\Models\DeviceEventState;
use App\Models\SharedEventState;
use Illuminate\Support\Collection;

interface EventStateRepository
{
    public function producerStates(string $provider): Collection;

    public function storeProducer(string $provider, string $id, array $state): void;

    public function consumerState(string $provider, string $id): ?DeviceEventState;

    public function storeConsumer(string $provider, string $id, array $state): void;

    public function nextGeneration(string $provider): int;

    public function sharedState(string $provider, string $kind, string $identity): ?SharedEventState;

    public function storeShared(string $provider, string $kind, string $identity, int $generation, string $fingerprint): void;
}
