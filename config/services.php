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

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'whatsapp' => [
        'id' => env('WA_ID'),
        'token' => env('WA_TOKEN'),
    ],

    'green_api' => [
        'host' => env('GREEN_API_HOST') ?: env('GREENAPI_BASE_URL', 'https://api.green-api.com'),
        'verify_ssl' => env('GREENAPI_VERIFY_SSL', true),
        'gateways' => [
            'manager_1' => [
                'host' => env('GREEN_API_MANAGER_1_HOST') ?: env('GREEN_API_HOST') ?: env('GREENAPI_BASE_URL', 'https://api.green-api.com'),
                'id' => env('GREEN_API_MANAGER_1_ID_INSTANCE') ?: env('GREEN_API_ID_INSTANCE') ?: env('GREENAPI_INSTANCE_ID'),
                'token' => env('GREEN_API_MANAGER_1_TOKEN') ?: env('GREEN_API_TOKEN') ?: env('GREENAPI_TOKEN'),
                'manager_email' => env('GREEN_API_MANAGER_1_EMAIL'),
            ],
            'manager_2' => [
                'host' => env('GREEN_API_MANAGER_2_HOST') ?: env('GREEN_API_HOST') ?: env('GREENAPI_BASE_URL', 'https://api.green-api.com'),
                'id' => env('GREEN_API_MANAGER_2_ID_INSTANCE'),
                'token' => env('GREEN_API_MANAGER_2_TOKEN'),
                'manager_email' => env('GREEN_API_MANAGER_2_EMAIL'),
            ],
            'manager_3' => [
                'host' => env('GREEN_API_MANAGER_3_HOST') ?: env('GREEN_API_HOST') ?: env('GREENAPI_BASE_URL', 'https://api.green-api.com'),
                'id' => env('GREEN_API_MANAGER_3_ID_INSTANCE'),
                'token' => env('GREEN_API_MANAGER_3_TOKEN'),
                'manager_email' => env('GREEN_API_MANAGER_3_EMAIL'),
            ],
        ],
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
