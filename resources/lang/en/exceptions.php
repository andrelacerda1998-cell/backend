<?php

return [
    'payment_methods' => [
        'credit_card_invalid_data' => 'Encryption key is not valid.',
        'disabled' => 'This payment method is currently unavailable.',
    ],
    'services' => [
        'service_not_found' => 'Service not found.',
        'customer_cannot_request_service' => 'Customer can´t request a service.',
        'customer_dont_have_balance' => 'Customer have insufficient balance.',
        'customer_dont_have_main_address' => 'Customer don´t have main Address.',
        'service_already_canceled' => 'Service already canceled.',
        'service_not_possible_to_cancel' => 'Service is not possible to cancel.',
        'payment_already_confirmed' => 'The payment was already confirmed — your request is moving forward.',
    ],
    'customer' => [
        'only_customers_allowed' => 'Only customers can access this endpoint.',
        'code_already_sent_recently' => 'Code already sent recently.',
    ],
    'user' => [
        'wrong_application' => 'Invalid Access: You are trying to access an application that is not intended for you. Please check your access.',
        'wrong_credentials' => 'Credentials are invalid',
    ],
    'vendor' => [
        'service' => [
            'service_is_not_pending' => 'Service is not pending.',
            'service_is_not_accepted' => 'Service has not been accepted.',
        ],
        'vendor_cannot_accept_service' => 'Vendor can´t accept service.',
        'has_service_open' => 'Vendor has open service.',
        'already_has_device_connected' => 'Vendor already has a device connected.',
        'vendor_cannot_invalid_workspace' => 'Invalid Invoice express workspace',
        'vendor_wrong_credentials' => 'At credentials are wrong',
        'cantDeleteAccountWithBalance' => 'Can´t delete account with balance',
        'cantDeleteAccountWithActiveServices' => 'Can´t delete account with active services',
        'account_not_validated' => 'Account not validated',
        'account_workspace_required' => 'Account workspace required',
        'payment_not_complete' => 'Payment not complete',
        'payment_refused' => 'Payment refused',
        'at_Account_need_attention' => 'Please check your AT credentials.',
    ],
    'common' => [
        'wrong_app_version' => 'Wrong app version.',
    ],
];
