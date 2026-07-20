<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Backoffice email 2FA
    |--------------------------------------------------------------------------
    |
    | Todos os utilizadores que acedem ao backoffice (admin/super-admin) recebem
    | um código por email a cada login. Tunables abaixo (não é um kill-switch).
    |
    */

    'code_length' => 6,

    'ttl_minutes' => 10,

    'resend_cooldown_seconds' => 60,

    'max_attempts' => 5,
];
