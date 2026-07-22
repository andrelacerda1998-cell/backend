<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Backoffice email 2FA
    |--------------------------------------------------------------------------
    |
    | Todos os utilizadores que acedem ao backoffice (admin/super-admin) recebem
    | um código por email a cada login. Tunables abaixo.
    |
    | 'enabled' é um kill-switch operacional: o 2FA só fica ativo com
    | BACKOFFICE_2FA_ENABLED=true explicitamente definido.
    |
    | ATENÇÃO: o default é FALSE (fail-open). Se a variável faltar (ambiente
    | novo, secret file do Jenkins por atualizar, deploy noutro host) o
    | backoffice fica em SINGLE FACTOR sem aviso no deploy. Decisão explícita
    | do Ederico (2026-07-18) para desbloquear admins sem mexer no secret file
    | 'piquetProdEnv'. NÃO reverter sem falar com ele.
    | Para religar: BACKOFFICE_2FA_ENABLED=true no 'piquetProdEnv'.
    |
    */

    'enabled' => env('BACKOFFICE_2FA_ENABLED', false),

    'code_length' => 6,

    'ttl_minutes' => 10,

    'resend_cooldown_seconds' => 60,

    'max_attempts' => 5,
];
