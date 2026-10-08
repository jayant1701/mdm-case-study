<?php

namespace App\Http\Controllers;

use App\Services\DemoControlService;

final class DemoController extends Controller
{
    public function reset(DemoControlService $demo): array
    {
        return $demo->reset();
    }

    public function simulateKafkaFailure(DemoControlService $demo): array
    {
        return $demo->simulateKafkaFailure();
    }
}
