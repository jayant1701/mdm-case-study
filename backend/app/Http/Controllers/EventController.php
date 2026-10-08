<?php

namespace App\Http\Controllers;

use App\Contracts\Persistence\EventInboxRepository;
use App\Services\DeviceEventSimulator;
use App\Services\EventReceiver;
use Illuminate\Http\Request;

final class EventController extends Controller
{
    public function __construct(private EventInboxRepository $events) {}

    public function index()
    {
        return ['data' => $this->events->recent(50)];
    }

    public function store(Request $request, EventReceiver $receiver)
    {
        $payload = $request->all();

        return response()->json($receiver->accept($payload), 202);
    }

    public function simulate(DeviceEventSimulator $simulator)
    {
        return response()->json($simulator->simulate(), 202);
    }

    public function retry(int $event, EventReceiver $receiver)
    {
        return response()->json($receiver->retry($event), 202);
    }
}
