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

    'apple' => [
        'bundle_id' => env('APPLE_BUNDLE_ID', 'com.arahinn.mobile'),
    ],

    // ── Payment mode ───────────────────────────────────────────────
    // 'doku'   = pakai DOKU SNAP BI VA gateway (production normal)
    // 'manual' = manual bank transfer (sementara, sebelum izin company beres)
    'payment' => [
        'mode' => env('PAYMENT_MODE', 'doku'),
        'manual_bank' => [
            'bank_name'       => env('PAYMENT_MANUAL_BANK_NAME', 'BCA'),
            'account_number'  => env('PAYMENT_MANUAL_ACCOUNT_NUMBER', '8040083848'),
            'account_name'    => env('PAYMENT_MANUAL_ACCOUNT_NAME', 'Rahmat Hidayattulah'),
            'expires_hours'   => env('PAYMENT_MANUAL_EXPIRES_HOURS', 24),
        ],
    ],

    'raja_biller' => [
        // Endpoint JSON API (devel atau production)
        'url'     => env('RAJA_BILLER_URL', 'https://c-dev-api.rajabiller.com/api_json.php'),
        // Credential (didapat dari Rajabiller via SMS saat registrasi)
        'uid'     => env('RAJA_BILLER_UID', ''),
        'pin'     => env('RAJA_BILLER_PIN', ''),
        // HTTP timeout (doctek: 45 detik untuk match Rajabiller behavior)
        'timeout' => env('RAJA_BILLER_TIMEOUT', 45),
        // Flag environment (untuk monitoring/log)
        'sandbox' => env('RAJA_BILLER_SANDBOX', true),
    ],

    // ── Rajabiller XAS (SAAS Travel Checkout Page) ─────────────────────
    // Used untuk integrasi tiket pesawat, kereta, bus (DLU), pelni.
    // Mode: Checkout Page — payment fully handled di Winpay/Rajabiller,
    // mitra cuma generate credential & redirect customer ke embed_fe_url.
    'raja_xas' => [
        'url'           => env('RAJA_XAS_URL', 'https://h2h-saas-checkout-page.rajabiller.com/api'),
        'client_key'    => env('RAJA_XAS_CLIENT_KEY', ''),       // UUID dari CS Rajabiller
        'client_secret' => env('RAJA_XAS_CLIENT_SECRET', ''),    // Header secret dari CS
        'id_outlet'     => env('RAJA_XAS_ID_OUTLET', env('RAJA_BILLER_UID', '')),
        'pin'           => env('RAJA_XAS_PIN',       env('RAJA_BILLER_PIN', '')),
        'merchant'      => env('RAJA_XAS_MERCHANT',  'arahinn'),
        'timeout'       => env('RAJA_XAS_TIMEOUT', 30),
        // Callback & redirect URL — diisi env supaya bisa di-override per env
        'url_callback'  => env('RAJA_XAS_CALLBACK_URL', env('APP_URL', 'https://ota-backend.arahinn.com') . '/api/v1/xas/callback'),
        'url_redirect'  => env('RAJA_XAS_REDIRECT_URL', env('FRONTEND_URL', 'https://arahinn.com') . '/tiket/result'),
    ],

    // ── Rajabiller Travel (DIRECT API) ─────────────────────────────────
    // API langsung (bukan XAS webview). Auth: JWT token via /app/sign_in
    // (outlet_id + pin), token valid 1 hari, dikirim di body. TANPA signature.
    // Doc: docs/rajabiller-travel-kai-api.md
    'raja_travel' => [
        // Per info CS Rajabiller (2026-06-04): KERETA(KAI) di DEVEL, PESAWAT & PELNI di PRODUCTION.
        // Dua channel terpisah (base URL + PIN berbeda), UID sama.
        'timeout' => env('RAJA_TRAVEL_TIMEOUT', 45),

        // KERETA (KAI) — DEVEL
        'kai_url'       => env('RAJA_TRAVEL_KAI_URL', 'https://c-dev-travel.rajabiller.com'),
        'kai_outlet_id' => env('RAJA_TRAVEL_KAI_OUTLET_ID', env('RAJA_BILLER_UID', 'SP347829')),
        'kai_pin'       => env('RAJA_TRAVEL_KAI_PIN', '311575'),

        // PESAWAT & PELNI — PRODUCTION (IP whitelist 103.76.121.180)
        'prod_url'       => env('RAJA_TRAVEL_URL', 'https://rajabiller.fastpay.co.id/travel'),
        'prod_outlet_id' => env('RAJA_TRAVEL_OUTLET_ID', env('RAJA_BILLER_UID', 'SP347829')),
        'prod_pin'       => env('RAJA_TRAVEL_PIN', env('RAJA_BILLER_PIN', '681768')),
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
