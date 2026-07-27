<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Token partilhado com o backoffice Next.js (piquet-backoffice) para as rotas
    // v1/admin/* — ver App\Http\Middleware\AdminApiToken.
    'admin_api' => [
        'token' => env('ADMIN_API_TOKEN'),
    ],

    'invoiceExpress' => [
        'account_name' => env('INVOICE_XPRESS_ACCOUNT'),
        'account_id' => env('INVOICE_XPRESS_ACCOUNT_ID'),
        'api_key' => env('INVOICE_XPRESS_API_KEY'),
        'product_name' => env('INVOICE_XPRESS_PRODUCT_NAME'),
        'protocol' => env('INVOICE_XPRESS_PROTOCOL', 'https'),
        'base_url' => env('INVOICE_XPRESS_BASE_URL', 'app.invoicexpress.com'),
        'account_prefix' => env('INVOICE_XPRESS_ACCOUNT_PREFIX', 'piquet_'),
        'email_address' => env('INVOICE_XPRESS_EMAIL'),
        'nif' => env('INVOICE_XPRESS_NIF'),
        'password' => env('INVOICE_XPRESS_PASSWORD'),
        'vat' => env('INVOICE_XPRESS_VAT', 23),
        'sequences' => env('INVOICE_SEQUENCES', 'PIQUET-%s-%s'),
        'sandbox' => env('INVOICE_XPRESS_SANDBOX', false),
    ],

];
