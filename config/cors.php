<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | As apps móveis nativas NÃO aplicam CORS (é um controlo de browser), pelo que
    | não são afetadas por esta configuração. O admin Filament é same-origin.
    |
    | Por defeito `CORS_ALLOWED_ORIGINS` = '*' mantém o comportamento atual (aberto)
    | até a env ser definida em produção — mesma filosofia do TRUSTED_PROXIES. Definir
    | a env para o(s) domínio(s) de browser permitidos (separados por vírgula) fecha o CORS.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS', '*')),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
