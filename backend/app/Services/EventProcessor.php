<?php

namespace App\Services;

use App\Contracts\Persistence\EventInboxRepository;
use App\Contracts\Persistence\EventStateRepository;
use App\Contracts\Persistence\SyncRunRepository;
use App\Contracts\Persistence\TransactionBoundary;
use App\Data\DeviceEvent;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class EventProcessor
{
    public function __construct(private JamfSyncService $sync, private EventInboxRepository $events, private SyncRunRepository $runs, private TransactionBoundary $transactions, private EventStateRepository $states) {}

    public function process(int $inboxId): void
    {
        $processed = false;
        try {
            $this->transactions->withProviderLock('jamf', function () use ($inboxId, &$processed) {
                $inbox = $this->events->require($inboxId);
                if ($inbox->status === 'succeeded') {
                    return;
                }
                $processed = true;
                $this->events->markProcessing($inboxId);
                // Payload is durable, self-contained state. This path never calls the provider.
                $event = DeviceEvent::parse($inbox->payload);
                $previous = $this->states->consumerState('jamf', $event->device->providerId);
                $version = $event->envelope['version'];
                $generation = $event->envelope['producer_generation'];
                if ($previous && ($version < $previous->version || $generation < $previous->producer_generation)) {
                    $this->events->markSucceeded($inboxId, 'ignored_stale');

                    return;
                }
                if ($previous && $version === $previous->version) {
                    if (DeviceEvent::semanticHash($previous->device, $previous->state) !== DeviceEvent::semanticHash($event->envelope['device'], $event->state())) {
                        throw new ConflictHttpException('Conflicting payload at an applied device version.');
                    }$this->events->markSucceeded($inboxId, 'ignored_duplicate');

                    return;
                }
                if ($previous && $previous->serial_code !== $event->device->serial) {
                    throw new RuntimeException('Provider identity cannot change its serial.');
                }
                $this->sync->applyEvent($event);
                $this->states->storeConsumer('jamf', $event->device->providerId, ['version' => $version, 'producer_generation' => $generation, 'state' => $event->state(), 'serial_code' => $event->device->serial, 'fingerprint' => $event->revisionHash(), 'device' => $event->envelope['device']]);
                $this->events->markSucceeded($inboxId, 'applied');
            });
        } catch (\Throwable $error) {
            if ($processed) {
                $this->transactions->withProviderLock('jamf', function () use ($inboxId, $error) {
                    if ($this->events->markFailedAttempt($inboxId, $error->getMessage())) {
                        $this->runs->recordFailedAttempt('jamf', 'event', $error->getMessage());
                    }
                });
            }
            throw $error;
        }
    }
}
