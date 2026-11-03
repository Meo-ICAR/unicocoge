<?php

return [
    /*
     * |--------------------------------------------------------------------------
     * | Third Party Services
     * |--------------------------------------------------------------------------
     * |
     * | This file is for storing the credentials for third party services such
     * | as Mailgun, Postmark, AWS and more. This file provides the de facto
     * | location for this type of information, allowing packages to have
     * | a conventional file to locate the various service credentials.
     * |
     */
    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],
    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],
    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect' => env('MICROSOFT_REDIRECT_URI'),
        'proxy' => env('PROXY'),  // Optional, will be used for all requests
    ],
    'sharepoint_proforma' => [
        // Cartella sotto la radice del drive SharePoint (credenziali SHARE_* del pacchetto unico-core) che raccoglie i dettagli delle banche: PROFORMA / <banca>
        'root' => env('PROFORMA_SHAREPOINT_ROOT', 'PROFORMA'),
    ],
    'bpm' => [
        'url' => env('BPM_API_URL'),
        // Chiave condivisa richiesta (header X-Api-Key) per autenticare le chiamate di UnicoBPM
        // al bridge generico esposto in routes/api.php (vedi App\Http\Middleware\VerifyBpmApiKey).
        'api_key' => env('BPM_BRIDGE_API_KEY'),
    ],
    'business_central' => [
        'tenant_id' => env('BC_TENANT_ID'),
        'client_id' => env('BC_CLIENT_ID'),
        'client_secret' => env('BC_CLIENT_SECRET'),
        'scope' => env('BC_SCOPE'),
        'environment' => env('BC_ENVIRONMENT'),
        'company_id' => env('BC_COMPANY_ID'),
        // Verso contabile da usare per le righe di prima nota inviate a Business Central:
        // 1  => riga "Dare" con importo positivo, riga "Avere" con importo negativo (standard contabile).
        // -1 => riga "Dare" con importo negativo, riga "Avere" con importo positivo. Convenzione adottata,
        //       coerente con le righe "Entrata" di coge:sync-monthly (vedi BC_PRIMANOTA_DARE_SIGN in .env).
        'primanota_dare_sign' => env('BC_PRIMANOTA_DARE_SIGN') !== null ? (int) env('BC_PRIMANOTA_DARE_SIGN') : null,
    ],
    'mediafacile' => [
        'base_url' => env('MEDIAFACILE_BASE_URL', 'https://races.mediafacile.it/ws/hassisto.php'),
        'header_key' => env('MEDIAFACILE_HEADER_KEY'),
        // Utenti (email) che ricevono l'esito dell'importazione schedulata.
        'notify_emails' => array_filter(array_map('trim', explode(',', (string) env('MEDIAFACILE_NOTIFY_EMAILS', '')))),
    ],
];
