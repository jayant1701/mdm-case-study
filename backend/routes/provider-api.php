<?php

use App\Http\Controllers\IndependentProviderController;
use Illuminate\Support\Facades\Route;

Route::prefix('provider')->group(function () {
    Route::get('/snapshot', [IndependentProviderController::class, 'snapshot']);
    Route::get('/devices/{device}', [IndependentProviderController::class, 'current']);
    Route::post('/import', [IndependentProviderController::class, 'import']);
    Route::post('/scan', [IndependentProviderController::class, 'scan']);
    Route::post('/seed', [IndependentProviderController::class, 'seed']);
    Route::post('/devices', [IndependentProviderController::class, 'create']);
    Route::patch('/devices/{device}', [IndependentProviderController::class, 'patch']);
    Route::delete('/devices/{device}', [IndependentProviderController::class, 'delete']);
});
