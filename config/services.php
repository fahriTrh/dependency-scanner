<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for any type of credentials that need to be available in
    | your application.
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
    |--------------------------------------------------------------------------
    | GitHub (opsional)
    |--------------------------------------------------------------------------
    |
    | Token GitHub tidak wajib. Tanpa token, request ke api.github.com dan
    | raw.githubusercontent.com memakai kuota anonim (±60 req/jam per IP).
    | Isi GITHUB_TOKEN di .env untuk kuota lebih besar (±5.000 req/jam).
    |
    */

    'github' => [
        'token' => env('GITHUB_TOKEN'),
    ],

];
