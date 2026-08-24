<?php
return [
    'plural' => 'Clientes',
    'singular' => 'Cliente',
    'action' => [
        'reset_password' => 'Repor password',
        'reset_password_form' => [
            'options' => [
                'email' => 'Enviar link de recuperação de password para email',
                'password_input' => 'Definir nova password'
            ]
        ]
    ],
    'table' => [
        'avatar' => 'Avatar',
        'name' => 'Nome',
        'nif' => 'NIF',
        'email' => 'Email',
        'phone_number' => 'Numero de telemovel',
        'canRequestService' => 'Pode solicitar serviços?',
        'all' => 'Todos'
    ],
    'form' => [
        'personal_data' => 'Dados pessoais',
        'contacts' => 'Contactos',
        'avatar' => 'Avatar',
        'avatar_placeholder' => 'Carregar avatar',
        'name' => 'Nome',
        'first_name' => 'Primeiro name',
        'last_name' => 'Ultimo name',
        'nif' => 'NIF',
        'email' => 'Email',
        'email_not_verified' => 'Email não verificado',
        'marked_as_verified' => 'Marcar como verificado',
        'phone_number' => 'Numero de telemovel',
        'phone_not_verified' => 'Numero de telemovel não verificado',
        'gender' => 'Gênero',
        'password' => 'Password',
        'date_birthday' => 'Data de nascimento',
    ],
    'infolist' => [
        'eligibility' => [
            'title' => 'Elegibilidade para solicitar serviços',
            'can_request' => 'O cliente pode solicitar serviços.',
            'unverified_phone' => 'O cliente não tem o número de telemóvel verificado.',
            'no_main_address' => 'O cliente não tem endereço principal.',
            'open_service' => 'O cliente tem um serviço em aberto: :services.',
        ],
    ],
    'stats' => [
        'total' => 'Total de clientes',
        'total_description' => 'Clientes registados',
        'eligible' => 'Podem solicitar serviço',
        'eligible_description' => ':percent% dos clientes',
        'new_this_month' => 'Novos este mês',
    ],
];
