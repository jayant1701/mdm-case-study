<?php

use App\Http\Controllers\DemoController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\MockProviderController;
use Illuminate\Support\Facades\Route;

if (config('mdm.role') === 'provider') {
    require __DIR__.'/provider-api.php';

    return;
}

Route::get('/assets', [InventoryController::class, 'assets']);
Route::get('/assets/{asset}', [InventoryController::class, 'asset']);
Route::get('/employees', [InventoryController::class, 'employees']);
Route::delete('/assets/{asset}', [InventoryController::class, 'deleteAsset']);
Route::delete('/employees/{employee}', [InventoryController::class, 'deleteEmployee']);
Route::post('/sync', [InventoryController::class, 'sync']);
Route::get('/sync-runs', [InventoryController::class, 'runs']);
Route::get('/deleted-assets', [InventoryController::class, 'deletedAssets']);
Route::post('/restorations', [InventoryController::class, 'restore']);

Route::get('/events', [EventController::class, 'index']);
Route::get('/provider-outbox', [EventController::class, 'providerOutbox']);
Route::post('/provider-outbox/retry', [EventController::class, 'retryProviderOutbox']);
Route::post('/events/jamf', [EventController::class, 'store']);
Route::post('/events/{event}/retry', [EventController::class, 'retry']);
Route::post('/simulator/jamf', [EventController::class, 'simulate']);
if (config('mdm.provider_mode') === 'local') {
    Route::get('/provider/jamf/devices/{device}', [MockProviderController::class, 'show']);
    Route::post('/provider/jamf/devices', [MockProviderController::class, 'store']);
    Route::patch('/provider/jamf/devices/{device}', [MockProviderController::class, 'update']);
    Route::delete('/provider/jamf/devices/{device}', [MockProviderController::class, 'destroy']);
    Route::post('/provider/jamf/seed', [MockProviderController::class, 'seed']);
}
Route::post('/sync/provider', [InventoryController::class, 'syncProvider']);

if (app()->environment('local', 'testing')) {
    Route::post('/demo/reset', [DemoController::class, 'reset']);
    Route::post('/demo/kafka-failure', [DemoController::class, 'simulateKafkaFailure']);
}
