<?php

namespace App\Http\Controllers;

use App\Contracts\Persistence\ProviderOutboxRepository;
use App\Services\IndependentProviderService;
use App\Services\ProducerOutboxPublisher;
use Illuminate\Http\Request;

final class IndependentProviderController extends Controller
{
    public function outbox(ProviderOutboxRepository $outbox)
    {
        return $outbox->activity();
    }

    public function retryOutbox(ProducerOutboxPublisher $publisher, ProviderOutboxRepository $outbox)
    {
        return $publisher->drain(10) + ['pending' => $outbox->pendingCount()];
    }

    public function snapshot(IndependentProviderService $provider)
    {
        return $provider->snapshot();
    }

    public function current(string $device, IndependentProviderService $provider)
    {
        return $provider->current($device);
    }

    public function import(Request $request, IndependentProviderService $provider)
    {
        return $provider->import($request->all());
    }

    public function scan(IndependentProviderService $provider)
    {
        return $provider->scan();
    }

    public function seed(IndependentProviderService $provider)
    {
        return $provider->seed();
    }

    public function create(Request $request, IndependentProviderService $provider)
    {
        return response()->json($provider->create($request->all()), 201);
    }

    public function patch(string $device, Request $request, IndependentProviderService $provider)
    {
        return $provider->patch($device, $request->all());
    }

    public function delete(string $device, IndependentProviderService $provider)
    {
        return $provider->delete($device);
    }
}
