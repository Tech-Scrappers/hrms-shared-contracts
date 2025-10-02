<?php

return [
    'enabled' => env('CORS_ENABLED', true),
    'allowed_origins' => [
        env('CORS_ALLOWED_ORIGINS', '*'),
    ],
    'allowed_methods' => [
        'GET',
        'POST',
        'PUT',
        'PATCH',
        'DELETE',
        'OPTIONS',
    ],
    'allowed_headers' => [
        'Content-Type',
        'Authorization',
        'X-Requested-With',
        'Accept',
        'Origin',
        'X-Tenant-Domain',
        'X-Request-ID',
        'HRMS-Client-Secret',
        'HRMS-Client-ID',
    ],
    'exposed_headers' => [
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'X-RateLimit-Reset',
        'Retry-After',
        'X-Request-ID',
    ],
    'max_age' => env('CORS_MAX_AGE', 86400),
    'supports_credentials' => env('CORS_SUPPORTS_CREDENTIALS', false),
    'environment_aware' => env('CORS_ENVIRONMENT_AWARE', true),
    'environments' => [
        'local' => [
            'allowed_origins' => ['*'],
            'supports_credentials' => false,
        ],
        'staging' => [
            'allowed_origins' => [
                'https://staging.hrms.com',
                'https://api-staging.hrms.com',
            ],
            'supports_credentials' => true,
        ],
        'production' => [
            'allowed_origins' => [
                'https://hrms.com',
                'https://api.hrms.com',
            ],
            'supports_credentials' => true,
        ],
    ],
];
