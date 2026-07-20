<?php

use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'vendor', 'middleware' => ['auth:api', 'locale', 'isVendor']], function () {
    Route::resource('documents', App\Http\Controllers\Api\Vendor\DocumentController::class)->only('index', 'show', 'store');

    // Route::group(['prefix' => 'services'], function () {
    //     Route::get('/', App\Http\Controllers\Api\Vendor\Services\ListPendingServicesController::class);
    // });

    Route::group(['prefix' => 'location'], function () {
        Route::put('/update', App\Http\Controllers\Api\Vendor\Location\UpdateLocationController::class);
    });

    Route::group(['prefix' => 'services'], function () {
        Route::group(['prefix' => 'operation-areas'], function () {
            Route::get('/', [App\Http\Controllers\Api\Vendor\Services\OperationAreasController::class, 'index'])
                ->withoutMiddleware('auth:api');
            Route::post('/', [App\Http\Controllers\Api\Vendor\Services\OperationAreasController::class, 'store']);

            Route::group(['prefix' => 'services-types'], function () {
                Route::get('/', [App\Http\Controllers\Api\Vendor\Services\ServiceTypesController::class, 'index']);
                Route::post('/', [App\Http\Controllers\Api\Vendor\Services\ServiceTypesController::class, 'store']);
            });
        });
        Route::post('/history', App\Http\Controllers\Api\Vendor\Services\ServicesHistoryController::class);
        Route::get('/', App\Http\Controllers\Api\Vendor\Services\CheckHasAnyServiceOpenController::class);
        Route::get('/pending', [App\Http\Controllers\Api\Vendor\Services\CheckHasAnyServicePendingController::class, 'service']);
        Route::get('/pending/all', [App\Http\Controllers\Api\Vendor\Services\CheckHasAnyServicePendingController::class, 'services']);
        Route::get('/{service}', [App\Http\Controllers\Api\Vendor\Services\CheckHasAnyServiceOpenController::class, 'service']);
        Route::group(['prefix' => '{service}'], function () {
            Route::get('/', App\Http\Controllers\Api\Vendor\Services\GetServiceDetailsController::class);
            Route::get('/route', App\Http\Controllers\Api\Common\GetServiceRouteController::class);
            Route::post('/accept', App\Http\Controllers\Api\Vendor\Services\AcceptServiceController::class);
            Route::post('/cancel', App\Http\Controllers\Api\Vendor\Services\CancelServiceController::class);
            Route::post('/finish', App\Http\Controllers\Api\Vendor\Services\FinishServiceController::class);
            Route::post('/refuse', App\Http\Controllers\Api\Vendor\Services\RefuseServiceController::class);
            Route::post('/arrived', App\Http\Controllers\Api\Vendor\Services\ArrivedServiceController::class);
            Route::put('/rate', App\Http\Controllers\Api\Vendor\Services\VendorRateServiceController::class);
        });
    });

    Route::group(['prefix' => 'address'], function () {
        Route::get('/', [App\Http\Controllers\Api\Vendor\AddressController::class, 'get']);
        Route::post('/', [App\Http\Controllers\Api\Vendor\AddressController::class, 'update']);
        Route::group(['prefix' => 'postal-code'], function () {
            Route::get('/verify', [App\Http\Controllers\Api\Vendor\AddressController::class, 'verify']);
        });
    });

    Route::post('/at-user', App\Http\Controllers\Api\Vendor\UpdateAtUserController::class);

    Route::group(['prefix' => 'status'], function () {
        Route::put('/', App\Http\Controllers\Api\Vendor\Status\StatusController::class);
        Route::get('/', [App\Http\Controllers\Api\Vendor\Status\StatusController::class, 'check']);
    });

    Route::group(['prefix' => 'settings'], function () {
        Route::put('/price-rate', App\Http\Controllers\Api\Vendor\Settings\UpdatePriceRateController::class);
        Route::put('/update/payment', [App\Http\Controllers\Api\Vendor\Settings\UpdatePaymentController::class, 'update']);
    });

    Route::group(['prefix' => 'wallet'], function () {
        Route::get('/', App\Http\Controllers\Api\Vendor\Wallet\WalletController::class);
        Route::post('/history', App\Http\Controllers\Api\Vendor\Wallet\WalletHistoryController::class);
    });

    Route::group(['prefix' => 'survey'], function () {
        Route::get('/cities', [App\Http\Controllers\Api\Vendor\Survey\SurveyCitiesController::class, 'index']);
        Route::post('/vote', [App\Http\Controllers\Api\Vendor\Survey\SurveyCitiesController::class, 'vote']);
    });

    Route::group(['prefix' => 'schedule'], function () {
        Route::get('/settings/{userId}', [App\Http\Controllers\Api\Vendor\Schedule\ScheduleController::class, 'settings']);
        Route::post('/update', [App\Http\Controllers\Api\Vendor\Schedule\ScheduleController::class, 'update']);
        Route::post('/update-availability', [App\Http\Controllers\Api\Vendor\Schedule\ScheduleController::class, 'updateAvailability']);
        Route::get('/schedules', [App\Http\Controllers\Api\Vendor\Schedule\ScheduleController::class, 'schedules']);
        Route::post('/accept', [App\Http\Controllers\Api\Vendor\Schedule\ScheduleController::class, 'storeSchedule']);
        Route::get('/pending-schedules', [App\Http\Controllers\Api\Vendor\Schedule\ScheduleController::class, 'pendingSchedules']);

        Route::get('/details/{schedule}', [App\Http\Controllers\Api\Vendor\Schedule\ScheduleController::class, 'getScheduleData']);
        Route::post('/go-to-location/{service}', App\Http\Controllers\Api\Vendor\Schedule\GoToLocationController::class);
        Route::post('/{schedule}/cancel', App\Http\Controllers\Api\Vendor\Schedule\CancelScheduleController::class);
    });
});
