<?php

namespace App\Http\Controllers;

use App\Models\EventInbox;
use App\Services\EventReceiver;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class EventController extends Controller
{
    public function index()
    {
        return ['data' => EventInbox::latest('id')->limit(50)->get()];
    }

    public function store(Request $request, EventReceiver $receiver)
    {
        $payload = $request->validate(['event_id' => ['required', 'string', 'max:255'], 'provider_device_id' => ['required', 'string', 'max:255'], 'type' => ['required', Rule::in(['device.changed', 'device.removed'])]]);

        return response()->json($receiver->accept($payload), 202);
    }

    public function retry(EventInbox $event, EventReceiver $receiver)
    {
        return response()->json($receiver->retry($event), 202);
    }
}
