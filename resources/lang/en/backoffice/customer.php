<?php
return [
    'plural' => 'Customers',
    'singular' => 'Customer',
    'action' => [
        'reset_password' => 'Reset password',
        'reset_password_form' => [
            'options' => [
                'email' => 'Enviar link de recuperação de password para email',
                'password_input' => 'Definir nova password'
            ]
        ]
    ],
    'table' => [
        'avatar' => 'Avatar',
        'name' => 'Name',
        'nif' => 'NIF',
        'email' => 'Email',
        'phone_number' => 'Phone number',
        'canRequestService' => 'Can request service?',
        'all' => 'All'
    ],
    'form' => [
        'personal_data' => 'Personal data',
        'contacts' => 'Contacts',
        'avatar' => 'Avatar',
        'avatar_placeholder' => 'Upload avatar',
        'name' => 'Name',
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'nif' => 'NIF',
        'email' => 'Email',
        'email_not_verified' => 'Email not verified',
        'marked_as_verified' => 'Marked as verified',
        'phone_number' => 'Phone number',
        'phone_not_verified' => 'Phone number not verified',
        'gender' => 'Gender',
        'password' => 'Password',
        'date_birthday' => 'Date of birth',
    ],
    'infolist' => [
        'eligibility' => [
            'title' => 'Service request eligibility',
            'can_request' => 'The customer can request services.',
            'unverified_phone' => 'The customer has not verified their phone number.',
            'no_main_address' => 'The customer has no main address.',
            'open_service' => 'The customer has an open service: :services.',
        ],
    ],
    'stats' => [
        'total' => 'Total customers',
        'total_description' => 'Registered customers',
        'eligible' => 'Can request service',
        'eligible_description' => ':percent% of customers',
        'new_this_month' => 'New this month',
    ],
];
