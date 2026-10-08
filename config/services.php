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
        // rtcm:write und channels:read braucht die neue sipgate-Anlage (Neo) zum Starten von Anrufen über /calls.
        'scopes' => 'sessions:calls:write rtcm:write channels:read history:read devices:read',
        'login_url' => 'https://login.sipgate.com/auth/realms/',
        'api_url' => 'https://api.sipgate.com/v2',
    ],

    /*
    | Microsoft 365 (E-Mail aus dem Vorgang): Jede Person verbindet ihr eigenes Postfach
    | (OAuth2, delegierte Rechte). Gesendet wird über Microsoft Graph, die Mail liegt danach
    | in Outlook unter „Gesendete Elemente“. App-Registrierung in Microsoft Entra, Anleitung
    | in docs/BETRIEB.md Abschnitt 15. Ohne client_id und Secret ist die Funktion aus.
    */
    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        // Mandanten-ID (Verzeichnis-ID) aus Entra. „organizations“ geht nur bei mehrinstanzfähigen Apps.
        'tenant' => env('MICROSOFT_TENANT_ID') ?: 'organizations',
        'redirect' => env('MICROSOFT_REDIRECT_URI') ?: rtrim((string) env('APP_URL'), '/').'/microsoft/callback',
        'scopes' => 'offline_access openid profile email User.Read Mail.Send',
        'login_url' => 'https://login.microsoftonline.com/',
        'graph_url' => 'https://graph.microsoft.com/v1.0',
    ],

    /*
    | Website-Eingang (POST /api/eingang): Kontaktformular und Kursheft-Anforderung der Website.
    | Gemeinsamer HMAC-Schlüssel mit der Website (inc/konfig.php, crm_schluessel).
    | Ohne Schlüssel antwortet die Schnittstelle mit 503.
    */
    'website' => [
        'intake_secret' => env('WEBSITE_INTAKE_SECRET'),
    ],

];
