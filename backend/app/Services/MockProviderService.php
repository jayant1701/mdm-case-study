<?php

namespace App\Services;

use App\Contracts\DeviceProvider;
use App\Contracts\Persistence\MockProviderRepository;
use App\Contracts\Persistence\TransactionBoundary;
use App\Contracts\TargetedDeviceProvider;
use App\Data\DeviceEvent;
use Illuminate\Support\Str;

final class MockProviderService
{
    public function __construct(private DeviceProvider $fixture, private TargetedDeviceProvider $adapter, private MockProviderRepository $devices, private DeviceEventSimulator $publisher, private EventReceiver $receiver, private TransactionBoundary $transactions) {}

    public function seed(): array
    {
        $result = $this->transactions->withProviderLock('jamf', function () {
            if ($this->devices->hasAny()) {
                return ['events' => []];
            }

return $this->publisher->capture($this->fixture->snapshot(), false);
        });

        return $this->dispatch($result);
    }

    public function get(string $id): array
    {
        $event = $this->adapter->current($id);
        if (! $event) {
            abort(404, 'Provider device not found.');
        }

return $event->envelope;
    }

    public function create(array $state): array
    {
        return $this->mutate($state['provider_device_id'] ?? '', function ($existing) use ($state) {
            if ($existing && $existing->state !== 'removed') {
                abort(409, 'Provider device already exists.');
            }

return $state;
        }, false);
    }

    public function patch(string $id, array $changes): array
    {
        return $this->mutate($id, function ($existing) use ($changes) {
            if (! $existing || $existing->state === 'removed') {
                abort(404, 'Provider device not found.');
            }

return array_replace_recursive($existing->device, $changes);
        }, false);
    }

    public function delete(string $id): array
    {
        return $this->mutate($id, function ($existing) {
            if (! $existing || $existing->state === 'removed') {
                abort(404, 'Provider device not found.');
            }

return $existing->device;
        }, true);
    }

    private function mutate(string $id, callable $stateChange, bool $removed): array
    {
        $result = $this->transactions->withProviderLock('jamf', function () use ($id, $stateChange, $removed) {
            $existing = $this->devices->find($id);
            $state = $stateChange($existing);
            $type = $removed ? 'device.removed' : (($state['email'] ?? null) === null ? 'device.unassigned' : 'device.changed');
            $event = DeviceEvent::parse(['event_id' => (string) Str::uuid(), 'provider_device_id' => $id, 'version' => 1, 'producer_generation' => 1, 'type' => $type, 'device' => $state]);
            if ($existing && $existing->serial_code !== $event->device->serial) {
                abort(409, 'Provider serial is immutable.');
            }
            if ($this->devices->serialUsedByOther($event->device->serial, $id)) {
                abort(409, 'Provider serial already belongs to another device.');
            }
            // Provider row and outbox share the same transaction; heartbeat-only updates emit none.
            $this->devices->store($id, $event->envelope['device'], $event->state());

            return $this->publisher->publish($event->device, $event->state());
        });
        $summary = $this->dispatch($result);
        $summary['provider_device_id'] = $id;

        return $summary;
    }

    private function dispatch(array $result): array
    {
        foreach ($result['events'] as $event) {
            $this->receiver->dispatch($event);
        }

return ['emitted' => count($result['events']), 'event_ids' => array_map(fn ($event) => $event->event_id,$result['events'])];
    }
}
