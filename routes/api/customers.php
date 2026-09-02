<?php

use App\Http\Controllers\Api\Common\GetServiceRouteController;
use App\Http\Controllers\Api\Customer\Address\GetCurrentAddressController;
use App\Http\Controllers\Api\Customer\Address\UpdateAddressController;
use App\Http\Controllers\Api\Customer\BillingInfoController;
use App\Http\Controllers\Api\Customer\PaymentMethods\AddCreditCardController;
use App\Http\Controllers\Api\Customer\PaymentMethods\DeletePaymentMethodController;
use App\Http\Controllers\Api\Customer\PaymentMethods\FlushGuestCreditCardController;
use App\Http\Controllers\Api\Customer\PaymentMethods\ListPaymentsMethodsController;
use App\Http\Controllers\Api\Customer\PaymentMethods\PaymentMethodDetailsController;
use App\Http\Controllers\Api\Customer\PaymentMethods\SetPaymentMethodAsDefaultController;
use App\Http\Controllers\Api\Customer\Schedule\CancelScheduleController;
use App\Http\Controllers\Api\Customer\Schedule\ListSchedulesController;
use App\Http\Controllers\Api\Customer\Schedule\ScheduleController;
use App\Http\Controllers\Api\Customer\Schedule\SearchScheduleVendorsController;
use App\Http\Controllers\Api\Customer\Schedule\VendorAvailabilityController;
use App\Http\Controllers\Api\Customer\Services\CalculateValueController;
use App\Http\Controllers\Api\Customer\Services\CancelPending3DSController;
use App\Http\Controllers\Api\Customer\Services\CancelServiceController;
use App\Http\Controllers\Api\Customer\Services\CheckHasAnyServiceOpenController;
use App\Http\Controllers\Api\Customer\Services\CheckHasAnyServicePendingController;
use App\Http\Controllers\Api\Customer\Services\CloseServiceController;
use App\Http\Controllers\Api\Customer\Services\CustomerRateServiceController;
use App\Http\Controllers\Api\Customer\Services\CustomerServicePhotosController;
use App\Http\Controllers\Api\Customer\Services\GetServiceDetailsController;
use App\Http\Controllers\Api\Customer\Services\MatchingController;
use App\Http\Controllers\Api\Customer\Services\OpenServiceController;
use App\Http\Controllers\Api\Customer\Services\OperationAreasController;
use App\Http\Controllers\Api\Customer\Services\RequestServiceController;
use App\Http\Controllers\Api\Customer\Services\ServiceExtrasController;
use App\Http\Controllers\Api\Customer\Services\ServicesHistoryController;
use App\Http\Controllers\Api\Customer\Vouchers\ValidateVoucherController;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'customer', 'middleware' => ['auth:api', 'locale']], function () {
    Route::group(['prefix' => 'services'], function () {
        Route::get('/', CheckHasAnyServiceOpenController::class);
        Route::get('/pending', CheckHasAnyServicePendingController::class);
        Route::post('/', RequestServiceController::class);
        Route::post('/calculate', CalculateValueController::class)->withoutMiddleware('auth:api')->middleware('throttle:geocode');
        // @legacy - fallback for older app versions, use /common/services/operation-areas instead
        Route::get('/operation-areas', [OperationAreasController::class, 'index'])->withoutMiddleware('auth:api');
        Route::get('/operation-areas/{operationArea}/services-types', [OperationAreasController::class, 'servicesTypes'])->withoutMiddleware('auth:api');
        Route::post('/operation-areas/search', [OperationAreasController::class, 'search'])->withoutMiddleware('auth:api')->middleware('throttle:geocode');
        Route::post('/open/credit-card', [OpenServiceController::class, 'creditCard'])->withoutMiddleware('auth:api');
        Route::post('/open/mbway', [OpenServiceController::class, 'mbway'])->withoutMiddleware('auth:api');

        // Fotos que o cliente junta ao pedido. Fora do grupo {service} de
        // propósito: são carregadas no checkout, quando o serviço ainda não
        // existe (ver CustomerServicePhotosController).
        Route::post('/photos', [CustomerServicePhotosController::class, 'store']);
        Route::delete('/photos/{media}', [CustomerServicePhotosController::class, 'destroy']);

        // Seleção de profissional: candidatos primeiro, pagamento no fim
        // (ver docs/matching.md). Fora do grupo {service} nos dois primeiros
        // porque abrem o pedido, quando ainda não há serviço.
        Route::group(['prefix' => 'matching'], function () {
            Route::post('/', [MatchingController::class, 'start']);
            Route::get('/{service}', [MatchingController::class, 'show'])->middleware('throttle:30,1');
            Route::post('/{service}/select/{candidate}', [MatchingController::class, 'select']);
            Route::post('/{service}/checkout', [MatchingController::class, 'checkout']);
        });

        Route::post('/history', ServicesHistoryController::class);
        Route::group(['prefix' => '{service}'], function () {
            Route::get('/', GetServiceDetailsController::class);
            // 15/min: o polling da app usa 6/min + force-check manual com cooldown de 10s
            Route::get('/payment-status', [OpenServiceController::class, 'checkPaymentStatus'])
                ->middleware('throttle:15,1');
            Route::get('/route', GetServiceRouteController::class);
            Route::post('/cancel', CancelServiceController::class);
            Route::post('/cancel-pending-3ds', CancelPending3DSController::class);
            Route::post('/close', CloseServiceController::class);
            Route::put('/rate', CustomerRateServiceController::class);

            // Tempo extra / peças pedidos pelo técnico — o cliente aprova ou recusa
            Route::get('/extras', [ServiceExtrasController::class, 'index']);
            Route::post('/extras/{extra}/approve', [ServiceExtrasController::class, 'approve']);
            Route::post('/extras/{extra}/reject', [ServiceExtrasController::class, 'reject']);
            // Repetir a cobrança de um extra aprovado que ficou sem forma de cobrar (ex.: sem
            // cartão gravado) — depois de o cliente adicionar um método de pagamento.
            Route::post('/extras/{extra}/retry-charge', [ServiceExtrasController::class, 'retryCharge']);
        });

    });

    Route::group(['prefix' => 'address'], function () {
        Route::put('/', UpdateAddressController::class)->withoutMiddleware('auth:api')->middleware('throttle:geocode');
        Route::get('/', GetCurrentAddressController::class);
    });

    // Multi-morada: um proprietário de vários alojamentos gere as moradas de cada casa.
    Route::group(['prefix' => 'addresses'], function () {
        Route::get('/', [App\Http\Controllers\Api\Customer\Address\AddressesController::class, 'index']);
        Route::post('/', [App\Http\Controllers\Api\Customer\Address\AddressesController::class, 'store'])->middleware('throttle:geocode');
        Route::put('/{address}', [App\Http\Controllers\Api\Customer\Address\AddressesController::class, 'update'])->middleware('throttle:geocode');
        Route::delete('/{address}', [App\Http\Controllers\Api\Customer\Address\AddressesController::class, 'destroy']);
        Route::put('/{address}/main', [App\Http\Controllers\Api\Customer\Address\AddressesController::class, 'setMain']);
    });

    Route::group(['prefix' => 'payment-methods'], function () {
        Route::post('credit-card', AddCreditCardController::class)->withoutMiddleware('auth:api')->middleware('throttle:credit-card');
        Route::post('credit-card/flush-guest', FlushGuestCreditCardController::class);
        Route::get('/', ListPaymentsMethodsController::class);
        Route::put('/{paymentMethod}', SetPaymentMethodAsDefaultController::class);
        Route::get('/{paymentMethod}', PaymentMethodDetailsController::class);
        Route::delete('/{paymentMethod}', DeletePaymentMethodController::class);
    });

    Route::group(['prefix' => 'billing'], function () {
        Route::get('/', [BillingInfoController::class, 'index'])->withoutMiddleware('auth:api');
        Route::post('/', [BillingInfoController::class, 'store']);
    });

    Route::group(['prefix' => 'schedule'], function () {
        Route::get('/', ListSchedulesController::class);
        Route::post('/vendors', SearchScheduleVendorsController::class);
        Route::get('/vendors/{vendor}/availability', VendorAvailabilityController::class)->withoutMiddleware('auth:api');
        Route::post('/', [ScheduleController::class, 'createPendingSchedule']);
        Route::get('/vendor/{vendorId}', [ScheduleController::class, 'vendor']);
        Route::get('/details/{schedule}', [ScheduleController::class, 'getScheduleData']);
        Route::post('/{schedule}/cancel', CancelScheduleController::class);
    });

    Route::group(['prefix' => 'vouchers'], function () {
        Route::post('/validate', ValidateVoucherController::class);
    });
});
