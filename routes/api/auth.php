<?php

use App\Http\Controllers\Api\Auth\CreateUserController;
use App\Http\Controllers\Api\Auth\DeviceNotificationsController;
use App\Http\Controllers\Api\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\Auth\GeneratePublicKeyController;
use App\Http\Controllers\Api\Auth\Guest\GuestSendOtpController;
use App\Http\Controllers\Api\Auth\Guest\GuestVerifyOtpController;
use App\Http\Controllers\Api\Auth\GuestRegisterController;
use App\Http\Controllers\Api\Auth\LocaleController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\PhoneLoginController;
use App\Http\Controllers\Api\Auth\PhoneLoginVerifyController;
use App\Http\Controllers\Api\Auth\ValidatePhoneNumberController;
use App\Http\Controllers\Api\Auth\ValidationPhoneNumberController;
use App\Http\Controllers\Api\Auth\VerificationController;
use App\Http\Controllers\Api\User\UserController;
use App\Http\Controllers\Api\Vendor\CreateVendorController;
use App\Http\Controllers\Api\Vendor\DocumentController;
use App\Http\Controllers\ResetPasswordController;

Route::group(['prefix' => 'auth', 'middleware' => 'locale'], function () {
    Route::group(['prefix' => 'registration'], function () {
        Route::post('/vendor', CreateVendorController::class);
        Route::get('/documents/types', [DocumentController::class, 'types']);

        Route::post('/customer', [CreateUserController::class, 'createCustomerUser']);
        Route::post('/verify-user', [CreateUserController::class, 'verifyUserDataToCreate']);
    });
    Route::group(['prefix' => 'login'], function () {
        Route::post('/', LoginController::class)
            ->middleware('throttle:5,15');
        Route::post('/forgot-password', ForgotPasswordController::class);
        Route::post('/phone', PhoneLoginController::class)
            ->middleware('throttle:otp');
        // Anti-abuso real: código de 6 dígitos + expiração de 5 min. Limite alto
        // apenas para tolerar retentativas do utilizador sem bloquear (429 falso).
        Route::post('/phone/verify', PhoneLoginVerifyController::class)
            ->middleware('throttle:20,1');
    });

    Route::post('/guest/register', GuestRegisterController::class)
        ->middleware('throttle:10,1');
    // Chaveado por telemóvel (throttle:otp), não por IP. O bloqueio de 5 min por número
    // em GuestSendOtpController continua a ser o verdadeiro anti-abuso; o teto de IP vem
    // da base 'api'.
    Route::post('/guest/phone/send-otp', GuestSendOtpController::class)
        ->middleware(['throttle:otp', 'throttle:10,1']);   // por-número + teto de IP 10/min
    // Anti-abuso real: código de 6 dígitos + expiração de 5 min. Limite alto
    // apenas para tolerar retentativas do utilizador sem bloquear (429 falso).
    Route::post('/guest/phone/verify-otp', GuestVerifyOtpController::class)
        ->middleware('throttle:20,1');
    Route::post('/reset-password', [ResetPasswordController::class, 'update']);
    Route::post('/locale', LocaleController::class);
    Route::post('/refresh', [UserController::class, 'refresh']);
    Route::delete('/logout', LogoutController::class);

    Route::get('/key', GeneratePublicKeyController::class);

    Route::middleware(['auth:api'])->group(function () {
        Route::get('/me', [UserController::class, 'me']);
        Route::post('/device', DeviceNotificationsController::class);
        Route::put('/profile/update', [UserController::class, 'update']);
        Route::group(['prefix' => 'email'], function () {
            Route::post('/send-confirmation', [VerificationController::class, 'send'])
                ->middleware(['throttle:6,1'])
                ->name('verification.send');
        });
        Route::get('/sms-validation', ValidationPhoneNumberController::class);
        Route::post('/sms-validation', ValidatePhoneNumberController::class);
    });
});
