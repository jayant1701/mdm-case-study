<?php

namespace App\Services;

use App\Contracts\Persistence\EventStateRepository;
use App\Data\DeviceEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class SharedMetadataPolicy
{
    public function __construct(private EventStateRepository $states) {}

    public function fields(string $kind, string $identity, int $generation, array $fields): array
    {
        $previous = $this->states->sharedState('jamf', $kind, $identity);
        $hash = DeviceEvent::hash($fields);
        if ($previous && $generation < $previous->generation) {
            return [];
        }
        if ($previous && $generation === $previous->generation && $previous->fingerprint !== $hash) {
            throw new ConflictHttpException('Conflicting shared metadata at the same producer generation.');
        }
        $this->states->storeShared('jamf', $kind, $identity, $generation, $hash);

        return $fields;
    }
}
