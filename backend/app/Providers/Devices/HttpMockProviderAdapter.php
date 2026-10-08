<?php

namespace App\Providers\Devices;

use App\Contracts\RemoteProviderGateway;
use App\Data\DeviceEvent;
use App\Data\DeviceSnapshot;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class HttpMockProviderAdapter implements RemoteProviderGateway
{
    public function current(string $providerId): ?DeviceEvent
    {
        $response = Http::acceptJson()->timeout(15)->get($this->url('/devices/'.rawurlencode($providerId)));
        if ($response->status() === 404) {
            return null;
        }
        $response->throw();

        $event = DeviceEvent::parse($response->json());
        if ($event->device->providerId !== $providerId) {
            throw new RuntimeException('Provider lookup returned a different device identity.');
        }

        return $event;
    }

    public function snapshot(): DeviceSnapshot
    {
        $envelopes = $this->envelopesSnapshot();
        $records = [];
        foreach ($envelopes['devices'] as $envelope) {
            $event = DeviceEvent::parse($envelope);
            if ($event->state() !== 'removed') {
                $records[] = $event->device;
            }
        }

        return new DeviceSnapshot($records, []);
    }

    public function envelopesSnapshot(): array
    {
        return $this->request('get', '/snapshot');
    }

    public function import(array $raw): array
    {
        return $this->request('post', '/import', $raw);
    }

    public function scan(): array
    {
        return $this->request('post', '/scan');
    }

    public function seed(): array
    {
        return $this->request('post', '/seed');
    }

    public function create(array $device): array
    {
        return $this->request('post', '/devices', $device);
    }

    public function patch(string $id, array $changes): array
    {
        return $this->request('patch', '/devices/'.rawurlencode($id), $changes);
    }

    public function delete(string $id): array
    {
        return $this->request('delete', '/devices/'.rawurlencode($id));
    }

    private function request(string $method, string $path, array $payload = []): array
    {
        $response = Http::acceptJson()->timeout(15)->send(strtoupper($method), $this->url($path), $method === 'get' ? [] : ['json' => $payload]);
        $response->throw();
        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException('Invalid mock-provider response.');
        }
        if (isset($data['devices'])) {
            if (! isset($data['generation']) || ! is_int($data['generation']) || $data['generation'] < 0 || ! is_array($data['devices']) || ! array_is_list($data['devices'])) {
                throw new RuntimeException('Invalid provider snapshot envelope.');
            }$seen = [];
            foreach ($data['devices'] as $envelope) {
                $event = DeviceEvent::parse($envelope);
                if (isset($seen[$event->device->providerId])) {
                    throw new RuntimeException('Duplicate device in provider snapshot.');
                }if ($event->envelope['producer_generation'] > $data['generation']) {
                    throw new RuntimeException('Device generation exceeds snapshot generation.');
                }$seen[$event->device->providerId] = true;
            }
        } elseif ($path === '/snapshot' || $path === '/import' || $path === '/scan' || $path === '/seed') {
            throw new RuntimeException('Provider response must contain complete devices snapshot.');
        }

        return $data;
    }

    private function url(string $path): string
    {
        return rtrim(config('mdm.provider_url', 'http://mock-provider:8000/api/provider'), '/').$path;
    }
}
