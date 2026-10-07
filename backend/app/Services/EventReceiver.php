<?php

namespace App\Services;

use App\Jobs\ProcessDeviceEvent;
use App\Models\EventInbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class EventReceiver
{
    public function accept(array $payload): EventInbox
    {
        $inbox = DB::transaction(function () use ($payload) {
            DB::table('sync_locks')->where('provider', 'jamf')->lockForUpdate()->first();
            $existing = EventInbox::where('event_id', $payload['event_id'])->first();
            if ($existing) {
                if ($existing->provider_device_id !== $payload['provider_device_id'] || $existing->type !== $payload['type']) {
                    abort(409, 'Event ID was already used for different content.');
                }

                return $existing;
            }

            return EventInbox::create(['event_id' => $payload['event_id'], 'provider' => 'jamf', 'provider_device_id' => $payload['provider_device_id'], 'type' => $payload['type'], 'payload' => $payload, 'status' => 'pending']);
        });
        if ($inbox->status === 'pending') {
            $this->dispatch($inbox);
        }

        return $inbox;
    }

    public function dispatch(EventInbox $inbox): void
    {
        try {
            ProcessDeviceEvent::dispatch($inbox->id);
        } catch (\Throwable $e) {
            Log::warning('Event dispatch deferred; durable inbox recovery will retry.', ['event_id' => $inbox->event_id, 'error' => $e->getMessage()]);
        }
    }

    public function recover(): int
    {
        $pending = EventInbox::where('status', 'pending')->orderBy('id')->limit(100)->get();
        foreach ($pending as $inbox) {
            $this->dispatch($inbox);
        }

        return $pending->count();
    }

    public function retry(EventInbox $inbox): EventInbox
    {
        DB::transaction(function () use ($inbox) {
            DB::table('sync_locks')->where('provider', 'jamf')->lockForUpdate()->first();
            $record = EventInbox::findOrFail($inbox->id);
            if ($record->status === 'failed') {
                $record->update(['status' => 'pending', 'last_error' => null]);
            }
        });
        $inbox->refresh();
        if ($inbox->status === 'pending') {
            $this->dispatch($inbox);
        }

        return $inbox;
    }
}
