<?php

use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'customer', 'middleware' => ['auth:api', 'locale']], function () {
    Route::group(['prefix' => 'services'], function () {
        Route::get('/', App\Http\Controllers\Api\Customer\Services\CheckHasAnyServiceOpenController::class);
        Route::get('/pending', App\Http\Controllers\Api\Customer\Services\CheckHasAnyServicePendingController::class);
        Route::post('/', App\Http\Controllers\Api\Customer\Services\RequestServiceController::class);
        Route::post('/calculate', App\Http\Controllers\Api\Customer\Services\CalculateValueController::class)->withoutMiddleware('auth:api')->middleware('throttle:geocode');
        // @legacy - fallback for older app versions, use /common/services/operation-areas instead
        Route::get('/operation-areas', [App\Http\Controllers\Api\Customer\Services\OperationAreasController::class, 'index'])->withoutMiddleware('auth:api');
        Route::get('/operation-areas/{operationArea}/services-types', [App\Http\Controllers\Api\Customer\Services\OperationAreasController::class, 'servicesTypes'])->withoutMiddleware('auth:api');
        Route::post('/operation-areas/search', [App\Http\Controllers\Api\Customer\Services\OperationAreasController::class, 'search'])->withoutMiddleware('auth:api')->middleware('throttle:geocode');
        Route::post('/open/credit-card', [App\Http\Controllers\Api\Customer\Services\OpenServiceController::class, 'creditCard'])->withoutMiddleware('auth:api');
        Route::post('/open/mbway', [App\Http\Controllers\Api\Customer\Services\OpenServiceController::class, 'mbway'])->withoutMiddleware('auth:api');

        Route::post('/history', App\Http\Controllers\Api\Customer\Services\ServicesHistoryController::class);
        Route::group(['prefix' => '{service}'], function () {
            Route::get('/', App\Http\Controllers\Api\Customer\Services\GetServiceDetailsController::class);
            // 15/min: o polling da app usa 6/min + force-check manual com cooldown de 10s
            Route::get('/payment-status', [App\Http\Controllers\Api\Customer\Services\OpenServiceController::class, 'checkPaymentStatus'])
                ->middleware('throttle:15,1');
            Route::get('/route', App\Http\Controllers\Api\Common\GetServiceRouteController::class);
            Route::post('/cancel', App\Http\Controllers\Api\Customer\Services\CancelServiceController::class);
            Route::post('/cancel-pending-3ds', App\Http\Controllers\Api\Customer\Services\CancelPending3DSController::class);
            Route::post('/close', App\Http\Controllers\Api\Customer\Services\CloseServiceController::class);
            Route::put('/rate', App\Http\Controllers\Api\Customer\Services\CustomerRateServiceController::class);

            // Tempo extra / peças pedidos pelo técnico — o cliente aprova ou recusa
            Route::get('/extras', [App\Http\Controllers\Api\Customer\Services\ServiceExtrasController::class, 'index']);
            Route::post('/extras/{extra}/approve', [App\Http\Controllers\Api\Customer\Services\ServiceExtrasController::class, 'approve']);
            Route::post('/extras/{extra}/reject', [App\Http\Controllers\Api\Customer\Services\ServiceExtrasController::class, 'reject']);
            // Repetir a cobrança de um extra aprovado que ficou sem forma de cobrar (ex.: sem
            // cartão gravado) — depois de o cliente adicionar um método de pagamento.
            Route::post('/extras/{extra}/retry-charge', [App\Http\Controllers\Api\Customer\Services\ServiceExtrasController::class, 'retryCharge']);
        });

    });

    Route::group(['prefix' => 'address'], function () {
        Route::put('/', App\Http\Controllers\Api\Customer\Address\UpdateAddressController::class)->withoutMiddleware('auth:api')->middleware('throttle:geocode');
        Route::get('/', App\Http\Controllers\Api\Customer\Address\GetCurrentAddressController::class);
    });

    Route::group(['prefix' => 'payment-methods'], function () {
        Route::post('credit-card', App\Http\Controllers\Api\Customer\PaymentMethods\AddCreditCardController::class)->withoutMiddleware('auth:api')->middleware('throttle:credit-card');
        Route::post('credit-card/flush-guest', App\Http\Controllers\Api\Customer\PaymentMethods\FlushGuestCreditCardController::class);
        Route::get('/', App\Http\Controllers\Api\Customer\PaymentMethods\ListPaymentsMethodsController::class);
        Route::put('/{paymentMethod}', App\Http\Controllers\Api\Customer\PaymentMethods\SetPaymentMethodAsDefaultController::class);
        Route::get('/{paymentMethod}', App\Http\Controllers\Api\Customer\PaymentMethods\PaymentMethodDetailsController::class);
        Route::delete('/{paymentMethod}', App\Http\Controllers\Api\Customer\PaymentMethods\DeletePaymentMethodController::class);
    });

    Route::group(['prefix' => 'billing'], function () {
        Route::get('/', [App\Http\Controllers\Api\Customer\BillingInfoController::class, 'index'])->withoutMiddleware('auth:api');
        Route::post('/', [App\Http\Controllers\Api\Customer\BillingInfoController::class, 'store']);
    });

    Route::group(['prefix' => 'schedule'], function () {
        Route::get('/', App\Http\Controllers\Api\Customer\Schedule\ListSchedulesController::class);
        Route::post('/vendors', App\Http\Controllers\Api\Customer\Schedule\SearchScheduleVendorsController::class);
        Route::get('/vendors/{vendor}/availability', App\Http\Controllers\Api\Customer\Schedule\VendorAvailabilityController::class)->withoutMiddleware('auth:api');
        Route::post('/', [App\Http\Controllers\Api\Customer\Schedule\ScheduleController::class, 'createPendingSchedule']);
        Route::get('/vendor/{vendorId}', [App\Http\Controllers\Api\Customer\Schedule\ScheduleController::class, 'vendor']);
        Route::get('/details/{schedule}', [App\Http\Controllers\Api\Customer\Schedule\ScheduleController::class, 'getScheduleData']);
        Route::post('/{schedule}/cancel', App\Http\Controllers\Api\Customer\Schedule\CancelScheduleController::class);
    });

    Route::group(['prefix' => 'vouchers'], function () {
        Route::post('/validate', App\Http\Controllers\Api\Customer\Vouchers\ValidateVoucherController::class);
    });
});
