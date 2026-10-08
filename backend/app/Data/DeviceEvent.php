<?php

namespace App\Data;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final readonly class DeviceEvent
{
    public function __construct(public array $envelope, public DeviceRecord $device) {}

    public static function fromRecord(DeviceRecord $record): array
    {
        return [
            'provider_device_id' => $record->providerId, 'serial_code' => $record->serial, 'email' => $record->email,
            'employee' => ['username' => $record->employee['username'] ?? null, 'name' => $record->employee['name'] ?? null, 'position' => $record->employee['position'] ?? null, 'phone' => $record->employee['phone'] ?? null],
            'model' => $record->model ? ['manufacturer' => $record->model['manufacturer'], 'model_identifier' => $record->model['model_identifier'], 'model_name' => $record->model['model_name'] ?? null, 'platform' => $record->model['platform'] ?? null, 'processor_type' => $record->model['processor_type'] ?? null, 'core_count' => isset($record->model['core_count']) ? (int) $record->model['core_count'] : null] : null,
            'asset' => ['device_name' => $record->asset['device_name'], 'udid' => $record->asset['udid'] ?? null, 'purchased' => $record->asset['purchased'] ?? null, 'purchase_date' => $record->asset['purchase_date'] ?? null, 'report_date' => $record->asset['report_date'] ?? null, 'last_contact_at' => $record->asset['last_contact_at'] ?? null, 'site_provider_id' => $record->asset['site_provider_id'] ?? null, 'site_name' => $record->asset['site_name'] ?? null],
            'specifications' => ['ram_gb' => isset($record->specifications['ram_gb']) ? (float) $record->specifications['ram_gb'] : null, 'storage_gb' => isset($record->specifications['storage_gb']) ? (float) $record->specifications['storage_gb'] : null, 'battery_capacity_percent' => isset($record->specifications['battery_capacity_percent']) ? (float) $record->specifications['battery_capacity_percent'] : null],
        ];
    }

    public static function parse(array $payload): self
    {
        Validator::make($payload, [
            'event_id' => ['required', 'string', 'max:255'], 'provider_device_id' => ['required', 'string', 'max:255'], 'version' => ['required', 'integer', 'min:1'], 'producer_generation' => ['required', 'integer', 'min:1'],
            'type' => ['required', Rule::in(['device.added', 'device.changed', 'device.unassigned', 'device.removed'])],
            'device' => ['required', 'array'], 'device.provider_device_id' => ['required', 'string', 'max:255'], 'device.serial_code' => ['required', 'string', 'max:255'], 'device.email' => ['present', 'nullable', 'email', 'max:255'],
            'device.employee' => ['required', 'array'], 'device.employee.username' => ['nullable', 'string', 'max:255'], 'device.employee.name' => ['present', 'nullable', 'string', 'max:255'], 'device.employee.position' => ['nullable', 'string', 'max:255'], 'device.employee.phone' => ['present', 'nullable', 'string', 'max:255'],
            'device.model' => ['present', 'nullable', 'array'], 'device.model.manufacturer' => ['required_with:device.model', 'string', 'max:255'], 'device.model.model_identifier' => ['required_with:device.model', 'string', 'max:255'], 'device.model.model_name' => ['nullable', 'string', 'max:255'], 'device.model.platform' => ['nullable', 'string', 'max:255'], 'device.model.processor_type' => ['nullable', 'string', 'max:255'], 'device.model.core_count' => ['nullable', 'integer', 'min:0'],
            'device.asset' => ['required', 'array'], 'device.asset.device_name' => ['required', 'string', 'max:255'], 'device.asset.udid' => ['present', 'nullable', 'string', 'max:255'], 'device.asset.purchased' => ['present', 'nullable', 'boolean'], 'device.asset.purchase_date' => ['present', 'nullable', 'date_format:Y-m-d'], 'device.asset.report_date' => ['present', 'nullable', 'date_format:Y-m-d H:i:s'], 'device.asset.last_contact_at' => ['nullable', 'date_format:Y-m-d H:i:s'], 'device.asset.site_provider_id' => ['nullable', 'string', 'max:255'], 'device.asset.site_name' => ['nullable', 'string', 'max:255'],
            'device.specifications' => ['required', 'array'], 'device.specifications.ram_gb' => ['present', 'nullable', 'numeric', 'min:0'], 'device.specifications.storage_gb' => ['present', 'nullable', 'numeric', 'min:0'], 'device.specifications.battery_capacity_percent' => ['present', 'nullable', 'numeric', 'between:0,100'],
        ])->validate();
        $state = $payload['device'];
        if ($state['model'] !== null && ! array_key_exists('model_name', $state['model'])) {
            throw ValidationException::withMessages(['device.model.model_name' => 'Complete model state requires model_name, which may be null.']);
        }
        if (trim($state['provider_device_id']) !== trim($payload['provider_device_id'])) {
            throw ValidationException::withMessages(['device.provider_device_id' => 'Envelope and device identities must match.']);
        }
        $email = $state['email'] === null ? null : strtolower(trim($state['email']));
        if (($payload['type'] === 'device.unassigned' && $email !== null) || (in_array($payload['type'], ['device.added', 'device.changed'], true) && $email === null)) {
            throw ValidationException::withMessages(['type' => 'Lifecycle type must agree with employee assignment.']);
        }
        $record = new DeviceRecord(trim($payload['provider_device_id']), strtoupper(trim($state['serial_code'])), $email, $state['employee'], $state['model'], $state['asset'], $state['specifications']);
        $envelope = ['event_id' => $payload['event_id'], 'provider_device_id' => $record->providerId, 'version' => (int) $payload['version'], 'producer_generation' => (int) $payload['producer_generation'], 'type' => $payload['type'], 'device' => self::fromRecord($record)];

        return new self($envelope, $record);
    }

    public function state(): string
    {
        return $this->envelope['type'] === 'device.removed' ? 'removed' : ($this->device->email === null ? 'unassigned' : 'assigned');
    }

    public function contentHash(): string
    {
        $content = $this->envelope;
        unset($content['event_id']);

        return self::hash($content);
    }

    public function revisionHash(): string
    {
        return self::hash(['state' => $this->state(), 'device' => $this->envelope['device']]);
    }

    public static function semanticHash(array $device, string $state): string
    {
        // Treat fields added in newer event shapes as null when reading old checkpoints.
        $device['employee'] = array_replace(['username' => null, 'position' => null], $device['employee'] ?? []);
        if (is_array($device['model'] ?? null)) {
            $device['model'] = array_replace(['platform' => null, 'processor_type' => null, 'core_count' => null], $device['model']);
        }
        $device['asset'] = array_replace(['last_contact_at' => null, 'site_provider_id' => null, 'site_name' => null], $device['asset'] ?? []);
        unset($device['specifications']['processor_type'], $device['specifications']['core_count']);
        unset($device['asset']['report_date'], $device['asset']['last_contact_at']);
        // MySQL JSON may round-trip 16.0 as 16; compare normalized hardware numbers.
        foreach (['ram_gb', 'storage_gb', 'battery_capacity_percent'] as $field) {
            if (isset($device['specifications'][$field])) {
                $device['specifications'][$field] = (float) $device['specifications'][$field];
            }
        }
        if (isset($device['model']['core_count'])) {
            $device['model']['core_count'] = (int) $device['model']['core_count'];
        }

        return self::hash(['state' => $state, 'device' => $device]);
    }

    public static function hash(array $value): string
    {
        return hash('sha256', json_encode(self::canonical($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function canonical(array $value): array
    {
        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonical($item);
            }
        }

        return $value;
    }
}
