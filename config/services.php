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

    'sendgrid' => [
        // One API key sends subscription emails for every site. Replace the
        // placeholder in .env with the real key. The "from" address of every
        // site's template must live on this verified sending domain.
        'key' => env('SENDGRID_API_KEY'),
        'from_domain' => env('SENDGRID_FROM_DOMAIN', 'example.com'),
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

    'revalidation' => [
        'secret' => env('REVALIDATE_SECRET'),
    ],

    'postback' => [
        'token' => env('CONVERSION_POSTBACK_TOKEN'),
    ],

];
