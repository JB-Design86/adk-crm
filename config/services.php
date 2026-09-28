<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
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

    /*
    | sipgate (Telefonie): Anruf per Klick und Abgleich der Anrufliste.
    | OAuth2-Client aus der sipgate-Konsole. Ohne client_id ist die Funktion aus.
    */
    'sipgate' => [
        'client_id' => env('SIPGATE_CLIENT_ID'),
        'client_secret' => env('SIPGATE_CLIENT_SECRET'),
        'redirect' => env('SIPGATE_REDIRECT_URI', rtrim((string) env('APP_URL'), '/').'/sipgate/callback'),
        'realm' => env('SIPGATE_REALM', 'third-party'),
        'scopes' => 'sessions:calls:write history:read devices:read',
        'login_url' => 'https://login.sipgate.com/auth/realms/',
        'api_url' => 'https://api.sipgate.com/v2',
    ],

];
