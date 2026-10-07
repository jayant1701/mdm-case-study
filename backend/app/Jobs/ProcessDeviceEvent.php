<?php

namespace App\Jobs;

use App\Services\EventProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessDeviceEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $inboxId) {}

    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function handle(EventProcessor $processor): void
    {
        $processor->process($this->inboxId);
    }
}
