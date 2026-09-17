<?php

use App\Http\Controllers\Api\Common\GetServiceRouteController;
use App\Http\Controllers\Api\Vendor\AddressController;
use App\Http\Controllers\Api\Vendor\Cities\CitiesController;
use App\Http\Controllers\Api\Vendor\DocumentController;
use App\Http\Controllers\Api\Vendor\Location\UpdateLocationController;
use App\Http\Controllers\Api\Vendor\NoShowController;
use App\Http\Controllers\Api\Vendor\ReviewsController;
use App\Http\Controllers\Api\Vendor\Schedule\CancelScheduleController;
use App\Http\Controllers\Api\Vendor\Schedule\ConfirmScheduleAttendanceController;
use App\Http\Controllers\Api\Vendor\Schedule\GoToLocationController;
use App\Http\Controllers\Api\Vendor\Schedule\ScheduleController;
use App\Http\Controllers\Api\Vendor\Schedule\UnavailableDaysController;
use App\Http\Controllers\Api\Vendor\Services\AcceptServiceController;
use App\Http\Controllers\Api\Vendor\Services\ArrivedServiceController;
use App\Http\Controllers\Api\Vendor\Services\CancelServiceController;
use App\Http\Controllers\Api\Vendor\Services\CheckHasAnyServiceOpenController;
use App\Http\Controllers\Api\Vendor\Services\CheckHasAnyServicePendingController;
use App\Http\Controllers\Api\Vendor\Services\FinishServiceController;
use App\Http\Controllers\Api\Vendor\Services\GetServiceDetailsController;
use App\Http\Controllers\Api\Vendor\Services\MatchingInvitationsController;
use App\Http\Controllers\Api\Vendor\Services\OnTheWayController;
use App\Http\Controllers\Api\Vendor\Services\OperationAreasController;
use App\Http\Controllers\Api\Vendor\Services\RefuseServiceController;
use App\Http\Controllers\Api\Vendor\Services\ServiceExtrasController;
use App\Http\Controllers\Api\Vendor\Services\ServicePhotosController;
use App\Http\Controllers\Api\Vendor\Services\ServicesHistoryController;
use App\Http\Controllers\Api\Vendor\Services\ServiceTypesController;
use App\Http\Controllers\Api\Vendor\Services\VendorRateServiceController;
use App\Http\Controllers\Api\Vendor\Settings\NotificationSettingsController;
use App\Http\Controllers\Api\Vendor\Settings\UpdatePaymentController;
use App\Http\Controllers\Api\Vendor\Settings\UpdatePriceRateController;
use App\Http\Controllers\Api\Vendor\StatsController;
use App\Http\Controllers\Api\Vendor\Status\StatusController;
use App\Http\Controllers\Api\Vendor\SupportTicketController;
use App\Http\Controllers\Api\Vendor\Survey\SurveyCitiesController;
use App\Http\Controllers\Api\Vendor\UpdateAtUserController;
use App\Http\Controllers\Api\Vendor\Wallet\WalletController;
use App\Http\Controllers\Api\Vendor\Wallet\WalletHistoryController;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'vendor', 'middleware' => ['auth:api', 'locale', 'isVendor']], function () {
    Route::resource('documents', DocumentController::class)->only('index', 'show', 'store');

    // Route::group(['prefix' => 'services'], function () {
    //     Route::get('/', App\Http\Controllers\Api\Vendor\Services\ListPendingServicesController::class);
    // });

    Route::group(['prefix' => 'location'], function () {
        Route::put('/update', UpdateLocationController::class);
    });

    Route::group(['prefix' => 'services'], function () {
        Route::group(['prefix' => 'operation-areas'], function () {
            Route::get('/', [OperationAreasController::class, 'index'])
                ->withoutMiddleware('auth:api');
            Route::post('/', [OperationAreasController::class, 'store']);

            Route::group(['prefix' => 'services-types'], function () {
                Route::get('/', [ServiceTypesController::class, 'index']);
                Route::post('/', [ServiceTypesController::class, 'store']);
            });
        });
        Route::post('/history', ServicesHistoryController::class);
        Route::get('/', CheckHasAnyServiceOpenController::class);
        Route::get('/pending', [CheckHasAnyServicePendingController::class, 'service']);
        Route::get('/pending/all', [CheckHasAnyServicePendingController::class, 'services']);
        // Convites de seleção de profissional (ver docs/matching.md). Antes do
        // grupo {service} porque a chave é o candidato, não o serviço — e
        // porque `matching` colidiria com o parâmetro {service}.
        Route::group(['prefix' => 'matching'], function () {
            Route::get('/', [MatchingInvitationsController::class, 'index']);
            // Padrões de procura, calculados a partir de pedidos reais.
            Route::get('/insights', [MatchingInvitationsController::class, 'insights'])->middleware('throttle:10,1');
            Route::post('/{candidate}/accept', [MatchingInvitationsController::class, 'accept']);
            Route::post('/{candidate}/decline', [MatchingInvitationsController::class, 'decline']);
        });

        Route::get('/{service}', [CheckHasAnyServiceOpenController::class, 'service']);
        Route::group(['prefix' => '{service}'], function () {
            Route::get('/', GetServiceDetailsController::class);
            Route::get('/route', GetServiceRouteController::class);
            Route::post('/accept', AcceptServiceController::class);
            Route::post('/cancel', CancelServiceController::class);
            Route::post('/finish', FinishServiceController::class);
            Route::post('/refuse', RefuseServiceController::class);
            Route::post('/on-the-way', OnTheWayController::class);
            Route::post('/arrived', ArrivedServiceController::class);
            Route::put('/rate', VendorRateServiceController::class);

            // Tempo extra / peças (aprovados pelo cliente) e fotos antes/depois
            Route::get('/extras', [ServiceExtrasController::class, 'index']);
            Route::post('/extras', [ServiceExtrasController::class, 'store']);
            Route::delete('/extras/{extra}', [ServiceExtrasController::class, 'destroy']);
            Route::get('/photos', [ServicePhotosController::class, 'index']);
            Route::post('/photos', [ServicePhotosController::class, 'store']);
        });
    });

    Route::group(['prefix' => 'address'], function () {
        Route::get('/', [AddressController::class, 'get']);
        Route::post('/', [AddressController::class, 'update']);
        Route::group(['prefix' => 'postal-code'], function () {
            Route::get('/verify', [AddressController::class, 'verify']);
        });
    });

    Route::post('/at-user', UpdateAtUserController::class);

    Route::group(['prefix' => 'status'], function () {
        Route::put('/', StatusController::class);
        Route::get('/', [StatusController::class, 'check']);
    });

    Route::group(['prefix' => 'settings'], function () {
        Route::get('/notifications', [NotificationSettingsController::class, 'show']);
        Route::put('/notifications', [NotificationSettingsController::class, 'update']);
        Route::put('/price-rate', UpdatePriceRateController::class);
        Route::put('/update/payment', [UpdatePaymentController::class, 'update']);
    });

    Route::group(['prefix' => 'wallet'], function () {
        Route::get('/', WalletController::class);
        Route::post('/history', WalletHistoryController::class);
    });

    Route::get('/stats', StatsController::class);
    Route::get('/reviews', ReviewsController::class);

    Route::group(['prefix' => 'support'], function () {
        Route::get('/tickets', [SupportTicketController::class, 'index']);
        Route::post('/tickets', [SupportTicketController::class, 'store']);
    });

    // Faltas registadas ao próprio técnico, e a contestação de cada uma
    // (abre um ticket de suporte — quem decide se houve engano é uma pessoa).
    Route::group(['prefix' => 'no-shows'], function () {
        Route::get('/', [NoShowController::class, 'index']);
        Route::post('/{service}/dispute', [NoShowController::class, 'dispute']);
    });

    Route::group(['prefix' => 'cities'], function () {
        Route::get('/', [CitiesController::class, 'index']);
        Route::post('/', [CitiesController::class, 'store']);
    });

    Route::group(['prefix' => 'survey'], function () {
        Route::get('/cities', [SurveyCitiesController::class, 'index']);
        Route::post('/vote', [SurveyCitiesController::class, 'vote']);
    });

    Route::group(['prefix' => 'schedule'], function () {
        Route::get('/settings/{userId}', [ScheduleController::class, 'settings']);
        Route::post('/update', [ScheduleController::class, 'update']);
        // So a morada de onde o tecnico sai para um agendado. O `/update`
        // exige tambem os dias da semana; no "completar perfil" ha uma coisa a
        // pedir, nao duas.
        Route::post('/address', [ScheduleController::class, 'updateAddress']);
        Route::post('/update-availability', [ScheduleController::class, 'updateAvailability']);
        Route::get('/schedules', [ScheduleController::class, 'schedules']);
        Route::post('/accept', [ScheduleController::class, 'storeSchedule']);
        Route::get('/pending-schedules', [ScheduleController::class, 'pendingSchedules']);

        // Indisponibilidade pontual (folga, doenca, ferias) — ver o controlador.
        Route::get('/unavailable-days', [UnavailableDaysController::class, 'index']);
        Route::post('/unavailable-days', [UnavailableDaysController::class, 'store']);
        Route::delete('/unavailable-days/{day}', [UnavailableDaysController::class, 'destroy']);

        Route::get('/details/{schedule}', [ScheduleController::class, 'getScheduleData']);
        Route::post('/go-to-location/{service}', GoToLocationController::class);
        Route::post('/{schedule}/cancel', CancelScheduleController::class);
        // Botão do lembrete das 72h: "confirmo que vou".
        Route::post('/{schedule}/confirm-attendance', ConfirmScheduleAttendanceController::class);
    });
});
