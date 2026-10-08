<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class KafkaNotificationReceiver
{
    public function __construct(private EventReceiver $receiver) {}

    // Kafka offsets may advance only after either inbox acceptance or durable quarantine.
    public function receive(string $payload, string $topic, int $partition, int $offset): void
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw new \JsonException('Expected an event object.');
            }
            $this->receiver->accept($decoded);
        } catch (\JsonException|ValidationException $error) {
            $this->quarantine($payload, $topic, $partition, $offset, $error);
        } catch (HttpExceptionInterface $error) {
            if ($error->getStatusCode() !== 409) {
                throw $error;
            }
            $this->quarantine($payload, $topic, $partition, $offset, $error);
        }
    }

    private function quarantine(string $payload, string $topic, int $partition, int $offset, \Throwable $error): void
    {
        DB::table('rejected_notifications')->updateOrInsert(
            ['topic' => $topic, 'partition' => $partition, 'offset' => $offset],
            ['payload' => $payload, 'error' => $error->getMessage(), 'created_at' => now()]
        );
    }
}
