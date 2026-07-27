<?php

use App\Http\Controllers\Api\Admin\FeeSettingsController;
use App\Http\Controllers\Api\Admin\SystemProfitController;
use App\Http\Controllers\Api\Admin\VoucherController;
use Illuminate\Support\Facades\Route;

// Consumida pelo backoffice Next.js (piquet-backoffice), servidor-a-servidor —
// ver App\Http\Middleware\AdminApiToken. Nunca exposta ao browser diretamente.
Route::group(['prefix' => 'admin', 'middleware' => 'admin.api'], function () {
    Route::get('/fee-settings', [FeeSettingsController::class, 'show']);
    Route::put('/fee-settings', [FeeSettingsController::class, 'update']);

    Route::get('/system-profit', [SystemProfitController::class, 'index']);

    // apiResource já só regista index/store/show/update/destroy (sem create/edit).
    Route::apiResource('vouchers', VoucherController::class);
});
