<?php

namespace App\Http\Controllers;

use App\Services\MockProviderService;
use Illuminate\Http\Request;

final class MockProviderController extends Controller
{
    public function show(string $device, MockProviderService $provider)
    {
        return $provider->get($device);
    }

    public function store(Request $request, MockProviderService $provider)
    {
        return response()->json($provider->create($request->all()), 201);
    }

    public function update(string $device, Request $request, MockProviderService $provider)
    {
        return $provider->patch($device, $request->all());
    }

    public function destroy(string $device, MockProviderService $provider)
    {
        return $provider->delete($device);
    }

    public function seed(MockProviderService $provider)
    {
        return response()->json($provider->seed(), 202);
    }
}
