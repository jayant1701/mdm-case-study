<?php

namespace App\Providers\Devices;

use App\Contracts\Persistence\EventStateRepository;
use App\Contracts\Persistence\MockProviderRepository;
use App\Contracts\TargetedDeviceProvider;
use App\Data\DeviceEvent;
use App\Data\DeviceSnapshot;
use App\Services\DeviceStateNormalizer;

final class MockProviderAdapter implements TargetedDeviceProvider
{
    public function __construct(private MockProviderRepository $devices, private EventStateRepository $states, private DeviceStateNormalizer $normalizer) {}

    public function current(string $providerId): ?DeviceEvent
    {
        $row = $this->devices->find($providerId);
        if (! $row || $row->state === 'removed') {
            return null;
        }
        $checkpoint = $this->states->producerState('jamf', $providerId);

        return DeviceEvent::parse(['event_id' => 'target-'.$providerId, 'provider_device_id' => $providerId, 'version' => $checkpoint?->version ?? 1, 'producer_generation' => $checkpoint?->producer_generation ?? 1, 'type' => $row->state === 'unassigned' ? 'device.unassigned' : 'device.changed', 'device' => $row->device]);
    }

    public function snapshot(): DeviceSnapshot
    {
        $records = [];
        foreach ($this->devices->active() as $row) {
            $records[] = $this->current($row->provider_device_id)->device;
        }

return $this->normalizer->canonicalSnapshot($records);
    }
}
