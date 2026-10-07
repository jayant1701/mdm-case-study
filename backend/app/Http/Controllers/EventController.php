<?php

namespace App\Http\Controllers;

use App\Contracts\Persistence\EventInboxRepository;
use App\Services\EventReceiver;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class EventController extends Controller
{
    public function __construct(private EventInboxRepository $events) {}

    public function index()
    {
        return ['data' => $this->events->recent(50)];
    }

    public function store(Request $request, EventReceiver $receiver)
    {
        $payload = $request->validate(['event_id' => ['required', 'string', 'max:255'], 'provider_device_id' => ['required', 'string', 'max:255'], 'type' => ['required', Rule::in(['device.changed', 'device.removed'])]]);

        return response()->json($receiver->accept($payload), 202);
    }

    public function retry(int $event, EventReceiver $receiver)
    {
        return response()->json($receiver->retry($event), 202);
    }
}
