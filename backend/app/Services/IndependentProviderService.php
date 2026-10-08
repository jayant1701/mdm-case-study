<?php

namespace App\Services;

use App\Data\DeviceEvent;
use App\Models\ProducerOutbox;
use App\Models\ProviderDevice;
use App\Providers\Devices\JamfMockProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class IndependentProviderService
{
    public function __construct(private JamfMockProvider $fixture, private DeviceStateNormalizer $normalizer) {}

    public function snapshot(): array
    {
        return $this->locked(fn () => $this->snapshotUnlocked());
    }

    public function current(string $id): array
    {
        return $this->locked(function () use ($id) {
            $row = ProviderDevice::where('provider_device_id', $id)->first();
            if (! $row) {
                abort(404, 'Provider device not found.');
            }

            return $this->envelope($row);
        });
    }

    public function scan(): array
    {
        return $this->locked(fn () => $this->importRecords($this->fixture->snapshot()->devices));
    }

    public function resetDemo(): array
    {
        return $this->locked(function () {
            ProducerOutbox::query()->delete();
            $this->importRecords($this->fixture->snapshot()->devices);
            ProducerOutbox::query()->delete();

            return $this->snapshotUnlocked() + [
                'active_devices' => ProviderDevice::where('state', '!=', 'removed')->count(),
                'pending' => 0,
            ];
        });
    }

    public function simulateKafkaFailure(): array
    {
        return $this->locked(function () {
            $row = ProviderDevice::where('state', 'assigned')->orderBy('id')->firstOrFail();
            $device = $row->device;
            $suffix = ' [Kafka recovery test]';
            $name = $device['asset']['device_name'];
            $device['asset']['device_name'] = str_ends_with($name, $suffix)
                ? substr($name, 0, -strlen($suffix))
                : $name.$suffix;
            $event = $this->validate($row->provider_device_id, $device, false);
            $summary = $this->commitPlans(
                [['device' => $event->device, 'state' => $event->state()]],
                ['attempts' => 1, 'last_error' => 'Simulated Kafka broker unavailable.', 'next_attempt_at' => now()->addHour()],
            );

            return [
                'affected' => $summary['emitted'],
                'pending' => ProducerOutbox::where('status', 'pending')->count(),
                'provider_device_id' => $row->provider_device_id,
            ];
        });
    }

    public function seed(): array
    {
        return $this->locked(fn () => ProviderDevice::exists() ? $this->snapshotUnlocked() + ['emitted' => 0, 'event_ids' => []] : $this->importRecords($this->fixture->snapshot()->devices));
    }

    public function import(array $raw): array
    {
        return $this->locked(fn () => $this->importRecords($this->fixture->parseResponse($raw)->devices));
    }

    public function create(array $device): array
    {
        return $this->mutate((string) ($device['provider_device_id'] ?? ''), $device, false, true);
    }

    public function patch(string $id, array $changes): array
    {
        return $this->mutate($id, $changes, false, false);
    }

    public function delete(string $id): array
    {
        return $this->mutate($id, [], true, false);
    }

    private function mutate(string $id, array $changes, bool $removed, bool $create): array
    {
        return $this->locked(function () use ($id, $changes, $removed, $create) {
            $existing = ProviderDevice::where('provider_device_id', $id)->first();
            if ($create && $existing && $existing->state !== 'removed') {
                abort(409, 'Provider device already exists.');
            }
            if (! $create && (! $existing || $existing->state === 'removed')) {
                abort(404, 'Provider device not found.');
            }
            $state = $create ? $changes : $this->merge($existing->device, $changes);
            $event = $this->validate($id, $state, $removed);
            if ($existing && $existing->serial_code !== $event->device->serial) {
                abort(409, 'Provider identity cannot change its serial.');
            }
            $plans = [['device' => $event->device, 'state' => $event->state()]];
            // Employee/model metadata is shared; notify each affected device at the same generation.
            if (! $removed) {
                foreach (ProviderDevice::where('state', '!=', 'removed')->where('provider_device_id', '!=', $id)->get() as $other) {
                    $otherState = $other->device;
                    $dirty = false;
                    if (isset($changes['employee']) && $state['email'] !== null && $otherState['email'] === $state['email']) {
                        $otherState['employee'] = $state['employee'];
                        $dirty = true;
                    }
                    if (array_key_exists('model', $changes) && $state['model'] !== null && $otherState['model'] !== null && $otherState['model']['manufacturer'] === $state['model']['manufacturer'] && $otherState['model']['model_identifier'] === $state['model']['model_identifier']) {
                        $otherState['model'] = $state['model'];
                        $dirty = true;
                    }
                    if ($dirty) {
                        $otherEvent = $this->validate($other->provider_device_id, $otherState, false);
                        $plans[] = ['device' => $otherEvent->device, 'state' => $otherEvent->state()];
                    }
                }
            }
            $summary = $this->commitPlans($plans);

            return $summary + ['device' => $this->envelope(ProviderDevice::where('provider_device_id', $id)->firstOrFail())];
        });
    }

    private function importRecords(array $records): array
    {
        $old = ProviderDevice::get()->keyBy('provider_device_id');
        $current = [];
        foreach ($records as $record) {
            if (! $record->providerId || ! $record->serial) {
                throw new RuntimeException('Provider import requires serial and provider ID for every device.');
            }$previous = $old->get($record->providerId);
            $current[] = $this->normalizer->complete($record, $previous?->device);
        }
        $snapshot = $this->normalizer->canonicalSnapshot($current);
        $plans = [];
        $seen = [];
        foreach ($snapshot->devices as $record) {
            $seen[$record->providerId] = true;
            $plans[] = ['device' => $record, 'state' => $record->email === null ? 'unassigned' : 'assigned'];
        }
        foreach ($old as $id => $row) {
            if (! isset($seen[$id]) && $row->state !== 'removed') {
                $plans[] = ['device' => DeviceEvent::parse($this->envelope($row))->device, 'state' => 'removed'];
            }
        }
        $summary = $this->commitPlans($plans);

        return $this->snapshotUnlocked() + $summary;
    }

    private function commitPlans(array $plans, array $outboxState = []): array
    {
        $changed = [];
        foreach ($plans as $plan) {
            $device = DeviceEvent::fromRecord($plan['device']);
            $id = $plan['device']->providerId;
            $old = ProviderDevice::where('provider_device_id', $id)->first();
            if ($old && $old->serial_code !== $device['serial_code']) {
                abort(409, 'Provider identity cannot change its serial.');
            }
            if (ProviderDevice::where('serial_code', $device['serial_code'])->where('provider_device_id', '!=', $id)->exists()) {
                abort(409, 'Serial belongs to another provider device.');
            }
            $hash = DeviceEvent::semanticHash($device, $plan['state']);
            if (! $old || $old->fingerprint !== $hash || $old->state !== $plan['state']) {
                $changed[] = $plan + ['old' => $old, 'serialized' => $device, 'hash' => $hash];
            } elseif ($old->device !== $device) {
                $old->update(['device' => $device]);
            }
        }
        if (! $changed) {
            return ['generation' => $this->generation(), 'emitted' => 0, 'event_ids' => []];
        }
        DB::table('provider_generation')->where('provider', 'jamf')->increment('generation');
        $generation = $this->generation();
        $ids = [];
        foreach ($changed as $plan) {
            $old = $plan['old'];
            $device = $plan['serialized'];
            $id = $device['provider_device_id'];
            $version = ($old?->version ?? 0) + 1;
            $type = $plan['state'] === 'removed' ? 'device.removed' : ($plan['state'] === 'unassigned' ? 'device.unassigned' : (! $old || $old->state === 'removed' ? 'device.added' : 'device.changed'));
            $event = DeviceEvent::parse(['event_id' => (string) Str::uuid(), 'provider_device_id' => $id, 'version' => $version, 'producer_generation' => $generation, 'type' => $type, 'device' => $device]);
            ProviderDevice::updateOrCreate(['provider_device_id' => $id], ['serial_code' => $device['serial_code'], 'version' => $version, 'producer_generation' => $generation, 'state' => $plan['state'], 'fingerprint' => $plan['hash'], 'device' => $event->envelope['device']]);
            ProducerOutbox::create([
                'event_id' => $event->envelope['event_id'],
                'provider_device_id' => $id,
                'payload' => $event->envelope,
                'status' => 'pending',
                'attempts' => $outboxState['attempts'] ?? 0,
                'last_error' => $outboxState['last_error'] ?? null,
                'next_attempt_at' => $outboxState['next_attempt_at'] ?? null,
            ]);
            $ids[] = $event->envelope['event_id'];
        }

        return ['generation' => $generation, 'emitted' => count($ids), 'event_ids' => $ids];
    }

    private function validate(string $id, array $device, bool $removed): DeviceEvent
    {
        $type = $removed ? 'device.removed' : (($device['email'] ?? null) === null ? 'device.unassigned' : 'device.changed');

        return DeviceEvent::parse(['event_id' => 'validate', 'provider_device_id' => $id, 'version' => 1, 'producer_generation' => 1, 'type' => $type, 'device' => $device]);
    }

    private function envelope(ProviderDevice $row): array
    {
        return ['event_id' => 'provider-'.$row->provider_device_id.'-'.$row->version, 'provider_device_id' => $row->provider_device_id, 'version' => $row->version, 'producer_generation' => $row->producer_generation, 'type' => $row->state === 'removed' ? 'device.removed' : ($row->state === 'unassigned' ? 'device.unassigned' : 'device.changed'), 'device' => $row->device];
    }

    private function snapshotUnlocked(): array
    {
        return ['generation' => $this->generation(), 'devices' => ProviderDevice::orderBy('provider_device_id')->get()->map(fn ($row) => $this->envelope($row))->all()];
    }

    private function generation(): int
    {
        return (int) DB::table('provider_generation')->where('provider', 'jamf')->value('generation');
    }

    private function locked(callable $operation): mixed
    {
        return DB::transaction(function () use ($operation) {
            $lock = DB::table('provider_generation')->where('provider', 'jamf')->lockForUpdate()->first();
            if (! $lock) {
                throw new RuntimeException('Provider generation lock is missing.');
            }

            return $operation();
        }, 3);
    }

    private function merge(array $state, array $patch): array
    {
        foreach ($patch as $key => $value) {
            $state[$key] = is_array($value) && isset($state[$key]) && is_array($state[$key]) ? $this->merge($state[$key], $value) : $value;
        }

        return $state;
    }
}
