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

    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT_URI'),
    ],

    'raja_biller' => [
        'base_url' => env('RAJA_BILLER_BASE_URL', 'https://sandbox.rajabiller.com'),
        'api_key'  => env('RAJA_BILLER_API_KEY', ''),
        'secret'   => env('RAJA_BILLER_SECRET', ''),
        'username' => env('RAJA_BILLER_USERNAME', ''),
        'sandbox'  => env('RAJA_BILLER_SANDBOX', true),
    ],

    'doku' => [
        // Legacy single-merchant config (fallback)
        'client_id'          => env('DOKU_CLIENT_ID'),
        'secret_key'         => env('DOKU_SECRET_KEY'),
        'private_key_path'   => env('DOKU_PRIVATE_KEY_PATH'),
        'private_key'        => env('DOKU_PRIVATE_KEY'),
        'partner_service_id' => env('DOKU_PARTNER_SERVICE_ID'),
        'partner_service_ids' => array_filter([
            'bca'     => env('DOKU_PARTNER_SERVICE_ID_BCA'),
            'mandiri' => env('DOKU_PARTNER_SERVICE_ID_MANDIRI'),
            'bri'     => env('DOKU_PARTNER_SERVICE_ID_BRI'),
            'bsi'     => env('DOKU_PARTNER_SERVICE_ID_BSI'),
        ]),
        'base_url'           => env('DOKU_BASE_URL', 'https://api-sandbox.doku.com'),

        // Multi-merchant pool (sandbox). Sistem akan pilih otomatis (round-robin).
        'merchants' => array_filter([
            'merchant1' => env('DOKU_M1_CLIENT_ID') ? [
                'client_id'          => env('DOKU_M1_CLIENT_ID'),
                'secret_key'         => env('DOKU_M1_SECRET_KEY'),
                'private_key_path'   => env('DOKU_M1_PRIVATE_KEY_PATH'),
                'private_key'        => env('DOKU_M1_PRIVATE_KEY'),
                'partner_service_id' => env('DOKU_M1_PARTNER_SERVICE_ID'),
                'partner_service_ids' => array_filter([
                    'bca'     => env('DOKU_M1_PARTNER_SERVICE_ID_BCA'),
                    'mandiri' => env('DOKU_M1_PARTNER_SERVICE_ID_MANDIRI'),
                    'bri'     => env('DOKU_M1_PARTNER_SERVICE_ID_BRI'),
                    'bsi'     => env('DOKU_M1_PARTNER_SERVICE_ID_BSI'),
                ]),
                'enabled'            => env('DOKU_M1_ENABLED', true),
            ] : null,
            'merchant2' => env('DOKU_M2_CLIENT_ID') ? [
                'client_id'          => env('DOKU_M2_CLIENT_ID'),
                'secret_key'         => env('DOKU_M2_SECRET_KEY'),
                'private_key_path'   => env('DOKU_M2_PRIVATE_KEY_PATH'),
                'private_key'        => env('DOKU_M2_PRIVATE_KEY'),
                'partner_service_id' => env('DOKU_M2_PARTNER_SERVICE_ID'),
                'partner_service_ids' => array_filter([
                    'bca'     => env('DOKU_M2_PARTNER_SERVICE_ID_BCA'),
                    'mandiri' => env('DOKU_M2_PARTNER_SERVICE_ID_MANDIRI'),
                    'bri'     => env('DOKU_M2_PARTNER_SERVICE_ID_BRI'),
                    'bsi'     => env('DOKU_M2_PARTNER_SERVICE_ID_BSI'),
                ]),
                'enabled'            => env('DOKU_M2_ENABLED', true),
            ] : null,
            'merchant3' => env('DOKU_M3_CLIENT_ID') ? [
                'client_id'          => env('DOKU_M3_CLIENT_ID'),
                'secret_key'         => env('DOKU_M3_SECRET_KEY'),
                'private_key_path'   => env('DOKU_M3_PRIVATE_KEY_PATH'),
                'private_key'        => env('DOKU_M3_PRIVATE_KEY'),
                'partner_service_id' => env('DOKU_M3_PARTNER_SERVICE_ID'),
                'partner_service_ids' => array_filter([
                    'bca'     => env('DOKU_M3_PARTNER_SERVICE_ID_BCA'),
                    'mandiri' => env('DOKU_M3_PARTNER_SERVICE_ID_MANDIRI'),
                    'bri'     => env('DOKU_M3_PARTNER_SERVICE_ID_BRI'),
                    'bsi'     => env('DOKU_M3_PARTNER_SERVICE_ID_BSI'),
                ]),
                'enabled'            => env('DOKU_M3_ENABLED', true),
            ] : null,
        ]),
    ],

];
