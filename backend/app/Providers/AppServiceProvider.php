<?php

namespace App\Providers;

use App\Contracts\DeviceProvider;
use App\Providers\Devices\JamfMockProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DeviceProvider::class, JamfMockProvider::class);
    }

    public function boot(): void {}
}
