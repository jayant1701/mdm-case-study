<?php

namespace App\Http\Controllers;

use App\Services\DemoControlService;
use Illuminate\Http\Request;

final class DemoController extends Controller
{
    public function reset(DemoControlService $demo): array
    {
        return $demo->reset();
    }

    public function simulateKafkaFailure(DemoControlService $demo): array
    {
        return $demo->simulateKafkaFailure();
    }

    public function createProviderDevice(Request $request, DemoControlService $demo): array
    {
        return $demo->createProviderDevice($request->validate([
            'provider_device_id' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'serial_code' => ['required', 'string', 'max:255'],
            'device_name' => ['required', 'string', 'max:255'],
            'employee_email' => ['required', 'email', 'max:255'],
            'employee_name' => ['required', 'string', 'max:255'],
            'ram_gb' => ['required', 'numeric', 'min:0'],
        ]));
    }

    public function updateProviderDevice(string $device, Request $request, DemoControlService $demo): array
    {
        return $demo->updateProviderDevice($device, $request->validate([
            'device_name' => ['required', 'string', 'max:255'],
            'ram_gb' => ['required', 'numeric', 'min:0'],
        ]));
    }

    public function removeProviderDevice(string $device, DemoControlService $demo): array
    {
        return $demo->removeProviderDevice($device);
    }
}
