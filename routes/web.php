<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::redirect('/', '/backoffice');

Route::group(['prefix' => 'reset-password'], function () {
    Route::get('/{token}', [App\Http\Controllers\ResetPasswordController::class, '_invoke'])
        ->middleware('guest')->name('password.reset');

    Route::post('/', [App\Http\Controllers\ResetPasswordController::class, 'update'])
        ->middleware('guest')->name('password.update');
});

Route::group(['prefix' => 'email'], function() {
    Route::get('/verify/{id}/{hash}', App\Http\Controllers\VerificationController::class)
        ->middleware(['signed'])
        ->name('verification.verify');

    Route::post("/resend", [App\Http\Controllers\VerificationController::class, 'resend'])
        ->middleware(['throttle:6,1'])
        ->name('verification.resend');
});

Route::get('/payshop/failure/{service}', App\Http\Controllers\Api\Customer\Services\Validation\FailureValidationController::class)->middleware('signed')->name('payshop_failure_order');

Route::get('/payshop/success/{service}', App\Http\Controllers\Api\Customer\Services\Validation\SuccessController::class)->middleware('signed')->name('payshop_success_order');

Route::get('/document/{path}', function (string $path) {
    $disk = Storage::disk('local');

    if (! $disk->exists($path)) {
        abort(404);
    }

    $mime = $disk->mimeType($path);
    $content = $disk->get($path);

    return Response::make($content, 200, [
        'Content-Type' => $mime,
        'Content-Disposition' => 'attachment; filename="'.basename($path).'"',
        'X-Content-Type-Options' => 'nosniff',
        'Cache-Control' => 'max-age=3600, private',
    ]);
})->where('path', '.*')->middleware('signed')->name('storage.local.temp');
