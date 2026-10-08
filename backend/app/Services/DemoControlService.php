<?php

namespace App\Services;

use App\Contracts\Persistence\DemoStateRepository;
use App\Contracts\RemoteProviderGateway;

final class DemoControlService
{
    public function __construct(private RemoteProviderGateway $provider, private DemoStateRepository $state) {}

    public function reset(): array
    {
        $provider = $this->provider->resetDemo();
        $this->state->resetInventory();

        return ['provider_devices' => $provider['active_devices'] ?? 0];
    }

    public function simulateKafkaFailure(): array
    {
        return $this->provider->simulateKafkaFailure();
    }

    public function createProviderDevice(array $input): array
    {
        $change = $this->provider->create([
            'provider_device_id' => $input['provider_device_id'],
            'serial_code' => strtoupper($input['serial_code']),
            'email' => strtolower($input['employee_email']),
            'employee' => ['username' => null, 'name' => $input['employee_name'], 'position' => null, 'phone' => null],
            'model' => ['manufacturer' => 'Demo', 'model_identifier' => 'DemoDevice1,1', 'model_name' => 'Provider Lab device', 'platform' => null, 'processor_type' => null, 'core_count' => null],
            'asset' => ['device_name' => $input['device_name'], 'udid' => null, 'purchased' => null, 'purchase_date' => null, 'report_date' => null, 'last_contact_at' => null, 'site_provider_id' => null, 'site_name' => null],
            'specifications' => ['ram_gb' => (float) $input['ram_gb'], 'storage_gb' => null, 'battery_capacity_percent' => null],
        ]);

        return $this->providerChange('create', $change);
    }

    public function updateProviderDevice(string $providerId, array $input): array
    {
        $change = $this->provider->patch($providerId, [
            'asset' => ['device_name' => $input['device_name']],
            'specifications' => ['ram_gb' => (float) $input['ram_gb']],
        ]);

        return $this->providerChange('update', $change);
    }

    public function removeProviderDevice(string $providerId): array
    {
        return $this->providerChange('remove', $this->provider->delete($providerId));
    }

    private function providerChange(string $action, array $change): array
    {
        return [
            'action' => $action,
            'provider_device_id' => data_get($change, 'device.provider_device_id'),
            'provider_event' => match ($action) {
                'create' => 'device.added',
                'update' => 'device.changed',
                'remove' => 'device.removed',
            },
            'notifications_created' => count($change['event_ids'] ?? []),
        ];
    }
}
