<?php

use App\Http\Controllers\EventController;
use App\Http\Controllers\InventoryController;
use Illuminate\Support\Facades\Route;

Route::get('/assets', [InventoryController::class, 'assets']);
Route::get('/assets/{asset}', [InventoryController::class, 'asset']);
Route::get('/employees', [InventoryController::class, 'employees']);
Route::delete('/assets/{asset}', [InventoryController::class, 'deleteAsset']);
Route::delete('/employees/{employee}', [InventoryController::class, 'deleteEmployee']);
Route::post('/sync', [InventoryController::class, 'sync']);
Route::get('/sync-runs', [InventoryController::class, 'runs']);
Route::post('/restorations', [InventoryController::class, 'restore']);

Route::get('/events', [EventController::class, 'index']);
Route::post('/events/jamf', [EventController::class, 'store']);
Route::post('/events/{event}/retry', [EventController::class, 'retry']);
