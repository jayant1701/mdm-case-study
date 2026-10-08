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
}
