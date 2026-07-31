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

    'astrology' => [
        'base_url' => env('ASTROLOGY_API_BASE_URL', 'https://json.astrologyapi.com/v1'),
        'access_token' => env('ASTROLOGYAPI_ACCESS_TOKEN', env('ASTROLOGY_API_KEY')),
        'api_key' => env('ASTROLOGY_API_KEY'),
    ],

    'mail_gateway' => [
        'url' => env('MAIL_GATEWAY_URL', 'https://api.yourgoodtime.in/api/internal/mail/send-otp'),
        'key' => env('MAIL_GATEWAY_KEY'),
        'timeout' => (int) env('MAIL_GATEWAY_TIMEOUT', 10),
        'use_local_mailer' => env('MAIL_GATEWAY_USE_LOCAL_MAILER', false),
    ],

    'razorpay' => [
        'key_id' => env('RAZORPAY_KEY_ID'),
        'key_secret' => env('RAZORPAY_KEY_SECRET'),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
    ],

    'prediction_billing' => [
        'business_timezone' => env('PREDICTION_BUSINESS_TIMEZONE', 'Asia/Kolkata'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
