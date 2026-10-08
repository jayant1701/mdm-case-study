<?php

namespace App\Http\Controllers;

use App\Contracts\Persistence\EventInboxRepository;
use App\Contracts\RemoteProviderGateway;
use App\Services\DeviceEventSimulator;
use App\Services\EventReceiver;
use Illuminate\Http\Request;

final class EventController extends Controller
{
    public function providerOutbox(RemoteProviderGateway $provider)
    {
        return $provider->outbox();
    }

    public function retryProviderOutbox(RemoteProviderGateway $provider)
    {
        return $provider->retryOutbox();
    }

    public function __construct(private EventInboxRepository $events) {}

    public function index()
    {
        return ['data' => $this->events->recent(50)];
    }

    public function failures(): array
    {
        return ['data' => $this->events->failed(50)->map(function ($event) {
            $device = $event->payload['device'] ?? [];
            $employee = $device['employee'] ?? [];

            return [
                'id' => $event->id,
                'type' => $event->type,
                'provider_device_id' => $event->provider_device_id,
                'device_name' => $device['asset']['device_name'] ?? null,
                'serial_code' => $device['serial_code'] ?? null,
                'employee_name' => $employee['name'] ?? null,
                'employee_email' => $device['email'] ?? null,
                'attempts' => $event->attempts,
                'failed_at' => $event->updated_at,
            ];
        })->values()];
    }

    public function store(Request $request, EventReceiver $receiver)
    {
        $payload = $request->all();

        return response()->json($receiver->accept($payload), 202);
    }

    public function simulate(DeviceEventSimulator $simulator)
    {
        if (config('mdm.provider_mode') === 'remote') {
            return response()->json(app(RemoteProviderGateway::class)->scan(), 202);
        }

        return response()->json($simulator->simulate(), 202);
    }

    public function retry(int $event, EventReceiver $receiver)
    {
        return response()->json($receiver->retry($event), 202);
    }
}
