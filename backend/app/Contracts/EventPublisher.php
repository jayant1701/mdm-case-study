<?php

namespace App\Contracts;

interface EventPublisher
{
    public function publish(array $payload): void;
}
