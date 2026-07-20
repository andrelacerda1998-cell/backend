<?php

use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'v1'], function () {
    Route::get("/", function () {
        return response()->json(['message' => 'Welcome to the API']);
    });

    require_once __DIR__.'/api/auth.php';
    require_once __DIR__.'/api/common.php';
    require_once __DIR__.'/api/customers.php';
    require_once __DIR__.'/api/vendors.php';
});
