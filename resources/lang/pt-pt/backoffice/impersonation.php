<?php

return [
    'action' => [
        'label' => 'Gerar código de impersonate',
        'modal_heading' => 'Gerar código de impersonate',
        'modal_description' => 'Um código temporário (15 min) será gerado para entrar na app como este utilizador. A acção fica registada.',
        'submit' => 'Gerar código',
    ],
    'result' => [
        'heading' => 'Código de impersonate gerado',
        'email_label' => 'Email',
        'code_label' => 'Código',
        'expires_in' => 'Válido durante :minutes minutos.',
        'close' => 'Fechar',
    ],
    'notifications' => [
        'user_not_found' => 'Utilizador não encontrado',
        'not_allowed_for_admins' => 'Não é permitido gerar código para administradores',
    ],
    'relation_manager' => [
        'title' => 'Códigos de impersonate',
        'columns' => [
            'generated_by' => 'Gerado por',
            'generated_at' => 'Gerado em',
            'expires_at' => 'Expira em',
            'used_at' => 'Usado em',
            'failed_attempts' => 'Tentativas falhadas',
            'status' => 'Estado',
        ],
        'status' => [
            'active' => 'Ativo',
            'used' => 'Usado',
            'expired' => 'Expirado',
            'blocked' => 'Bloqueado',
        ],
    ],
];
