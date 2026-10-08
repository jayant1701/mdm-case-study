<?php

use App\Services\EventReceiver;
use App\Services\MockProviderService;
use Illuminate\Support\Facades\Artisan;

if (config('mdm.role') === 'provider') {
    require __DIR__.'/provider.php';

    return;
}
require __DIR__.'/kafka.php';

Artisan::command('events:recover', function (EventReceiver $receiver) {
    $this->info('Dispatched '.$receiver->recover().' pending inbox records.');
})->purpose('Recover notifications persisted before a queue dispatch failure');
Artisan::command('provider:seed', function (MockProviderService $provider) {
    $result = $provider->seed();
    $this->info('Provider seed queued '.$result['emitted'].' events.');
});
