<?php

use App\Services\IndependentProviderService;
use App\Services\ProducerOutboxPublisher;
use Illuminate\Support\Facades\Artisan;

Artisan::command('provider:publish {--limit=100}', function (ProducerOutboxPublisher $publisher) {
    $this->info(json_encode($publisher->drain((int) $this->option('limit'))));
})->purpose('Publish durable provider outbox messages to Kafka');
Artisan::command('provider:initialize', function (IndependentProviderService $provider) {
    $this->info(json_encode($provider->seed()));
});
