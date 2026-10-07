<?php

use App\Services\EventReceiver;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('events:recover', function (EventReceiver $receiver) {
    $this->info('Dispatched '.$receiver->recover().' pending inbox records.');
})->purpose('Recover notifications persisted before a queue dispatch failure');
