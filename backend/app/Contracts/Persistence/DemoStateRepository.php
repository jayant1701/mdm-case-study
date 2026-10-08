<?php

namespace App\Contracts\Persistence;

interface DemoStateRepository
{
    public function resetInventory(): void;
}
