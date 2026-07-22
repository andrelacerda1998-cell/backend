<?php

use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'v1'], function () {
    Route::get("/", function () {
        return response()->json(['message' => 'Welcome to the API']);
    });

    // NOTA: usar require (não require_once). O `require_once` marca o caminho do ficheiro
    // como "já incluído" a nível de processo PHP, não por instância da app. Em testes,
    // o Laravel arranca uma Application nova por teste — a partir do 2º teste que arranca
    // a framework no mesmo processo, o `require_once` fica no-op e estas rotas nunca são
    // registadas no novo Router, dando 404 em tudo o que está nestes ficheiros. Em produção
    // isto nunca se nota (só há um boot por processo), mas nos testes escondia falhas reais.
    require __DIR__.'/api/auth.php';
    require __DIR__.'/api/common.php';
    require __DIR__.'/api/customers.php';
    require __DIR__.'/api/vendors.php';
});
