<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Persistence\EventStateRepository;
use App\Models\DeviceEventState;
use App\Models\EventInbox;
use App\Models\SharedEventState;
use App\Models\SimulatedProviderDevice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class EloquentEventStateRepository implements EventStateRepository
{
    public function maximumConsumerGeneration(string $provider): int
    {
        return (int) DeviceEventState::where('provider', $provider)->max('producer_generation');
    }

    public function producerStates(string $provider): Collection
    {
        return SimulatedProviderDevice::where('provider', $provider)->get()->keyBy('provider_device_id');
    }

    public function producerState(string $provider, string $id): ?SimulatedProviderDevice
    {
        return SimulatedProviderDevice::where('provider', $provider)->where('provider_device_id', $id)->first();
    }

    public function storeProducer(string $provider, string $id, array $state): void
    {
        SimulatedProviderDevice::updateOrCreate(['provider' => $provider, 'provider_device_id' => $id], $state);
    }

    public function consumerState(string $provider, string $id): ?DeviceEventState
    {
        return DeviceEventState::where('provider', $provider)->where('provider_device_id', $id)->first();
    }

    public function storeConsumer(string $provider, string $id, array $state): void
    {
        DeviceEventState::updateOrCreate(['provider' => $provider, 'provider_device_id' => $id], $state);
    }

    public function nextGeneration(string $provider): int
    {
        $current = (int) DB::table('simulator_generations')->where('provider', $provider)->value('generation');
        $next = max($current, (int) DeviceEventState::where('provider', $provider)->max('producer_generation'), (int) SharedEventState::where('provider', $provider)->max('generation'), (int) EventInbox::where('provider', $provider)->max('producer_generation')) + 1;
        DB::table('simulator_generations')->where('provider', $provider)->update(['generation' => $next]);

        return $next;
    }

    public function sharedState(string $provider, string $kind, string $identity): ?SharedEventState
    {
        return SharedEventState::where('provider', $provider)->where('kind', $kind)->where('identity', $identity)->first();
    }

    public function storeShared(string $provider, string $kind, string $identity, int $generation, string $fingerprint): void
    {
        SharedEventState::updateOrCreate(['provider' => $provider, 'kind' => $kind, 'identity' => $identity], ['generation' => $generation, 'fingerprint' => $fingerprint]);
    }
}
