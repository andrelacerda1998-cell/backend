<?php

use App\Http\Controllers\Api\Common\AppNeedsUpdateController;
use App\Http\Controllers\Api\Common\CheckZoneController;
use App\Http\Controllers\Api\Common\DeleteAccountController;
use App\Http\Controllers\Api\Common\GooglePlacesAutocompleteController;
use App\Http\Controllers\Api\Common\NotificationsController;
use App\Http\Controllers\Api\Common\PaymentMethodsController;
use App\Http\Controllers\Api\Common\PublicKeyController;
use App\Http\Controllers\Api\Common\Services\ChatController;
use App\Http\Controllers\Api\Common\Services\ListServicesTypeController;
use App\Http\Controllers\Api\Customer\Services\CalculateValueController;
use App\Http\Controllers\Api\Customer\Services\OperationAreasController;
use App\Http\Controllers\Api\Customer\Services\RequestServiceController;
use App\Http\Controllers\Api\User\GenderController;

Route::group(['prefix' => 'common', 'middleware' => 'locale'], function () {
    Route::group(['prefix' => 'services'], function () {
        Route::get('/types', ListServicesTypeController::class);
        Route::get('/operation-areas', [OperationAreasController::class, 'index']);
        Route::get('/operation-areas/{operationArea}/services-types', [OperationAreasController::class, 'servicesTypes']);
        Route::post('/operation-areas/search', [OperationAreasController::class, 'search'])->middleware('throttle:geocode');
        Route::post('/guest/vendors', [RequestServiceController::class, 'guestSearch']);
        Route::post('/guest/calculate', [CalculateValueController::class, 'guestCalculate'])->middleware('throttle:geocode');
        Route::group(['prefix' => '{service}',  'middleware' => 'auth:api'], function () {
            Route::get('/public-key', PublicKeyController::class);
            Route::post('message', [ChatController::class, 'store']);
            Route::get('message', [ChatController::class, 'index']);
        });
    });
    Route::post('/app-update', AppNeedsUpdateController::class);
    Route::get('/app-version', App\Http\Controllers\Api\Common\AppVersionController::class);
    Route::get('/payment-methods', PaymentMethodsController::class);
    Route::post('/account/delete', DeleteAccountController::class)->middleware('auth:api');
    Route::get('/genders', GenderController::class);
    Route::get('/notifications', NotificationsController::class)
        ->middleware('auth:api');
    Route::group(['prefix' => 'notifications', 'middleware' => 'auth:api'], function () {
        Route::post('/campaign-log/{log}/open', [App\Http\Controllers\Api\Common\CampaignNotificationTrackingController::class, 'open'])->withoutMiddleware('auth:api');
        Route::post('/campaign-log/{log}/click', [App\Http\Controllers\Api\Common\CampaignNotificationTrackingController::class, 'click'])->withoutMiddleware('auth:api');
        Route::post('/opt-out', [App\Http\Controllers\Api\Common\NotificationOptOutController::class, 'store']);
        Route::delete('/opt-out', [App\Http\Controllers\Api\Common\NotificationOptOutController::class, 'destroy']);
    });
    // Eventos de produto da app, em lote. Throttle generoso porque a app envia
    // agregado; auth opcional para não perder os eventos de onboarding.
    Route::post('/analytics/events', App\Http\Controllers\Api\Common\AnalyticsController::class)
        ->middleware(['throttle:60,1']);
    Route::post('/places/autocomplete', GooglePlacesAutocompleteController::class)
        ->middleware(['throttle:30,1']);
    Route::post('/check-zone', CheckZoneController::class)
        ->middleware(['throttle:60,1']);
});
