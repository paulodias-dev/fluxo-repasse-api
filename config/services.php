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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'expo_push' => [
        'enabled' => env('EXPO_PUSH_ENABLED', false),
        'access_token' => env('EXPO_PUSH_ACCESS_TOKEN', ''),
    ],

    'apple_native' => [
        'enabled' => env('APPLE_NATIVE_ENABLED', false),
        'audience' => env('APPLE_NATIVE_AUDIENCE', ''),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', env('FRONTEND_URL', 'http://localhost:5173').'/auth/login'),
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

    // Tokens somente no Laravel. Nenhuma URL remota é aceita do navegador.
    'vehicle_plate' => [
        'token' => env('PLACAEFIPE_API_KEY', ''),
    ],
    'vehicle_fipe' => [
        'token' => env('PARALLELUM_FIPE_TOKEN', ''),
    ],
    'vehicle_catalog' => [
        'provider' => env('VEHICLE_CATALOG_PROVIDER', 'parallelum'),
        'apifipe' => [
            'base_url' => env('APIFIPE_API_BASE_URL', 'https://api.apifipe.com.br'),
            'token' => env('APIFIPE_API_TOKEN', ''),
        ],
    ],
];
