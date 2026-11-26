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
        'send_url' => env('MAIL_GATEWAY_SEND_URL'),
        'key' => env('MAIL_GATEWAY_KEY'),
        'timeout' => (int) env('MAIL_GATEWAY_TIMEOUT', 10),
        'use_local_mailer' => env('MAIL_GATEWAY_USE_LOCAL_MAILER', false),
    ],

    'otp_mail' => [
        'system' => env('OTP_MAIL_SYSTEM', 'local'),
    ],

    'zeptomail_api' => [
        'url' => env(
            'ZEPTOMAIL_API_URL',
            'https://api.zeptomail.in/v1.1/email'
        ),
        'token' => env('ZEPTOMAIL_TOKEN'),
        'from_address' => env(
            'ZEPTOMAIL_FROM_ADDRESS',
            'support@yourgoodtime.in'
        ),
        'from_name' => env(
            'ZEPTOMAIL_FROM_NAME',
            'Your Good Time'
        ),
        'connect_timeout' => (int) env(
            'ZEPTOMAIL_API_CONNECT_TIMEOUT',
            5
        ),
        'timeout' => (int) env(
            'ZEPTOMAIL_API_TIMEOUT',
            10
        ),
    ],

    'razorpay' => [
        'key_id' => env('RAZORPAY_KEY_ID'),
        'key_secret' => env('RAZORPAY_KEY_SECRET'),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
    ],

    'google_calendar' => [
        'calendar_id' => env('GOOGLE_CALENDAR_ID'),
        'service_account_json' => env('GOOGLE_CALENDAR_SERVICE_ACCOUNT_JSON'),
        'service_account_path' => env('GOOGLE_CALENDAR_SERVICE_ACCOUNT_PATH'),
        'delegated_user' => env('GOOGLE_CALENDAR_DELEGATED_USER'),
        'timezone' => env('GOOGLE_CALENDAR_TIMEZONE', 'Asia/Kolkata'),
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
