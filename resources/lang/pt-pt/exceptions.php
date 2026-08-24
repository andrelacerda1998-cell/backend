<?php

return [
    'payment_methods' => [
        'credit_card_invalid_data' => 'A chave de encriptação não é válida.',
        'disabled' => 'Este método de pagamento não está disponível de momento.',
    ],
    'services' => [
        'service_not_found' => 'Serviço não encontrado.',
        'verify_phone_to_request' => 'Verifica o teu número de telemóvel para pedires um serviço. É por aí que o profissional te contacta quando chega.',
        'customer_cannot_request_service' => 'O cliente não pode solicitar um serviço.',
        'customer_dont_have_balance' => 'O cliente não tem saldo suficiente.',
        'customer_dont_have_main_address' => 'O cliente não tem uma morada principal.',
        'service_already_canceled' => 'O serviço já foi cancelado.',
        'service_not_possible_to_cancel' => 'O serviço não pode ser cancelado.',
        'payment_already_confirmed' => 'O pagamento já foi confirmado — o pedido segue em frente.',
    ],
    'customer' => [
        'only_customers_allowed' => 'Apenas clientes podem aceder a este endpoint.',
        'code_already_sent_recently' => 'Código já foi enviado recentemente.',
    ],
    'user' => [
        'wrong_application' => 'Acesso inválido: Está a tentar aceder a uma aplicação que não é destinada a si. Por favor, verifique o seu acesso.',
        'wrong_credentials' => 'Credenciais inválidas.',
    ],
    'vendor' => [
        'service' => [
            'service_is_not_pending' => 'O serviço não está pendente.',
            'service_is_not_accepted' => 'O serviço não foi aceite.',
        ],
        'vendor_cannot_accept_service' => 'O profissional não pode aceitar o serviço.',
        'has_service_open' => 'O profissional tem um serviço aberto.',
        'already_has_device_connected' => 'O profissional já tem um disposítivo conectado.',
        'vendor_cannot_invalid_workspace' => 'Workspace do Invoice express é inválido',
        'vendor_wrong_credentials' => 'Credenciais AT inválidas.',
        'cantDeleteAccountWithBalance' => 'Não pode apagar a conta com saldo.',
        'cantDeleteAccountWithActiveServices' => 'Não pode apagar a conta com serviços ativos.',
        'account_not_validated' => 'A sua conta ainda não foi validada.',
        'account_workspace_required' => 'É necessário um workspace para a conta.',
        'payment_not_complete' => 'Pagamento ainda não foi concluido',
        'payment_refused' => 'Pagamento recusado',
        'at_Account_need_attention' => 'Por favor, verifique as suas credenciais AT na Piquet.',
    ],
    'common' => [
        'wrong_app_version' => 'Versão do app errada.',
    ],
];
