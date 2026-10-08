<?php

namespace App\Providers;

use App\Contracts\DeviceProvider;
use App\Contracts\EventPublisher;
use App\Contracts\Persistence\AssetRepository;
use App\Contracts\Persistence\DeviceModelRepository;
use App\Contracts\Persistence\EmployeeRepository;
use App\Contracts\Persistence\EventInboxRepository;
use App\Contracts\Persistence\EventStateRepository;
use App\Contracts\Persistence\MockProviderRepository;
use App\Contracts\Persistence\ProviderLockRepository;
use App\Contracts\Persistence\RestoreTaskRepository;
use App\Contracts\Persistence\SyncRunRepository;
use App\Contracts\Persistence\TransactionBoundary;
use App\Contracts\RemoteProviderGateway;
use App\Contracts\TargetedDeviceProvider;
use App\Messaging\KafkaEventPublisher;
use App\Providers\Devices\HttpMockProviderAdapter;
use App\Providers\Devices\JamfMockProvider;
use App\Providers\Devices\MockProviderAdapter;
use App\Repositories\Eloquent\DatabaseProviderLockRepository;
use App\Repositories\Eloquent\DatabaseTransactionBoundary;
use App\Repositories\Eloquent\EloquentAssetRepository;
use App\Repositories\Eloquent\EloquentDeviceModelRepository;
use App\Repositories\Eloquent\EloquentEmployeeRepository;
use App\Repositories\Eloquent\EloquentEventInboxRepository;
use App\Repositories\Eloquent\EloquentEventStateRepository;
use App\Repositories\Eloquent\EloquentMockProviderRepository;
use App\Repositories\Eloquent\EloquentRestoreTaskRepository;
use App\Repositories\Eloquent\EloquentSyncRunRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EventPublisher::class, KafkaEventPublisher::class);
        $this->app->bind(DeviceProvider::class, JamfMockProvider::class);
        $this->app->bind(RemoteProviderGateway::class, HttpMockProviderAdapter::class);
        $this->app->bind(TargetedDeviceProvider::class, fn ($app) => $app->make(config('mdm.provider_mode') === 'remote' ? HttpMockProviderAdapter::class : MockProviderAdapter::class));
        foreach ([
            AssetRepository::class => EloquentAssetRepository::class,
            MockProviderRepository::class => EloquentMockProviderRepository::class,
            EventStateRepository::class => EloquentEventStateRepository::class,
            EmployeeRepository::class => EloquentEmployeeRepository::class,
            DeviceModelRepository::class => EloquentDeviceModelRepository::class,
            SyncRunRepository::class => EloquentSyncRunRepository::class,
            RestoreTaskRepository::class => EloquentRestoreTaskRepository::class,
            EventInboxRepository::class => EloquentEventInboxRepository::class,
            ProviderLockRepository::class => DatabaseProviderLockRepository::class,
            TransactionBoundary::class => DatabaseTransactionBoundary::class,
        ] as $contract => $implementation) {
            $this->app->bind($contract, $implementation);
        }
    }

    public function boot(): void {}
}
