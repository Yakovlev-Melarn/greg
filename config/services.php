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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'wildberries' => [
        // Delay between per-card cards/upload calls (clone fallback) to reduce 429 from global limiter
        'upload_pause_ms_between_cards' => (int) env('WB_UPLOAD_PAUSE_MS_BETWEEN_CARDS', 500),
        // content/v2/cards/delete/trash — лимиты «per seller»; большие батчи дают 429
        'trash_batch_chunk_size' => max(1, min(100, (int) env('WB_TRASH_BATCH_CHUNK_SIZE', 20))),
        // Доп. пауза между чанками (мс). Базовый ритм — trash_ratelimit_* по доке WB; при 0 только интервал.
        'trash_inter_chunk_pause_ms' => max(0, (int) env('WB_TRASH_INTER_CHUNK_PAUSE_MS', 0)),
        'trash_max_attempts_per_chunk' => max(1, min(40, (int) env('WB_TRASH_MAX_ATTEMPTS_PER_CHUNK', 15))),
        // Интервал между запросами = период / лимит (пример WB: 60 с / 300 = 0,2 с)
        'trash_ratelimit_requests_per_period' => max(1, (int) env('WB_TRASH_RATELIMIT_REQUESTS', 300)),
        'trash_ratelimit_period_seconds' => max(1, (int) env('WB_TRASH_RATELIMIT_PERIOD_SECONDS', 60)),
    ],

];
