<?php

namespace Tests\Feature;

use App\Contracts\DeviceProvider;
use App\Data\DeviceEvent;
use Tests\InventoryTestCase;

final class NumericStateTest extends InventoryTestCase
{
    public function test_integer_json_round_trip_does_not_change_semantic_hardware_hash(): void
    {
        $device = DeviceEvent::fromRecord(app(DeviceProvider::class)->snapshot()->devices[0]);
        $roundTripped = json_decode(json_encode($device, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsFloat($device['specifications']['ram_gb']);
        $this->assertIsInt($roundTripped['specifications']['ram_gb']);
        $this->assertSame(DeviceEvent::semanticHash($device, 'assigned'), DeviceEvent::semanticHash($roundTripped, 'assigned'));
        $roundTripped['specifications']['ram_gb'] = 64;
        $this->assertNotSame(DeviceEvent::semanticHash($device, 'assigned'), DeviceEvent::semanticHash($roundTripped, 'assigned'));
    }

    public function test_legacy_optional_fields_are_canonicalized_for_hash_comparison(): void
    {
        $legacy = DeviceEvent::fromRecord(app(DeviceProvider::class)->snapshot()->devices[0]);
        $legacy['model']['platform'] = null;
        $legacy['model']['processor_type'] = null;
        $legacy['model']['core_count'] = null;
        $legacy['specifications']['processor_type'] = null;
        $legacy['specifications']['core_count'] = null;
        unset(
            $legacy['employee']['username'],
            $legacy['employee']['position'],
            $legacy['model']['platform'],
            $legacy['model']['processor_type'],
            $legacy['model']['core_count'],
            $legacy['asset']['last_contact_at'],
            $legacy['asset']['site_provider_id'],
            $legacy['asset']['site_name'],
        );
        $canonical = DeviceEvent::parse([
            'event_id' => 'legacy',
            'provider_device_id' => $legacy['provider_device_id'],
            'version' => 1,
            'producer_generation' => 1,
            'type' => 'device.changed',
            'device' => $legacy,
        ])->envelope['device'];

        $this->assertSame(DeviceEvent::semanticHash($legacy, 'assigned'), DeviceEvent::semanticHash($canonical, 'assigned'));
    }
}
