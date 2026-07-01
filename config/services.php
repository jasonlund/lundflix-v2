<?php

declare(strict_types=1);

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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'tmdb' => [
        'token' => env('TMDB_TOKEN'),
        'concurrency' => env('TMDB_CONCURRENCY', 20),
    ],

    'tvdb' => [
        'key' => env('TVDB_KEY'),
        'concurrency' => env('TVDB_CONCURRENCY', 10),
    ],

    'downloads' => [
        'uid' => env('DOWNLOADS_UID'),
        'pass' => env('DOWNLOADS_PASS'),
    ],

    // Per-retry backoff multiplier for the global guzzle-retry middleware (escalating
    // semantics in HttpClientServiceProvider::retryOptions). Per-environment because
    // guzzle-retry sleeps via its own usleep and bypasses Sleep::fake() — phpunit.xml
    // pins this to 0 so retry tests don't really sleep.
    'http_retry' => [
        'retry_multiplier' => env('HTTP_RETRY_MULTIPLIER', 1.0),
    ],

];
