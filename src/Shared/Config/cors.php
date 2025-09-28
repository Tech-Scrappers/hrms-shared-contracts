<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the CORS configuration for the HRMS microservices
    | system. It provides environment-specific settings for different
    | deployment environments (development, staging, production).
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'],

    'allowed_origins' => [
        // Environment-specific origins will be loaded from .env
        ...explode(',', env('CORS_ALLOWED_ORIGINS', '')),
    ],

    'allowed_origins_patterns' => [
        // Environment-specific patterns will be loaded from .env
        ...array_filter(explode(',', env('CORS_ALLOWED_PATTERNS', ''))),
    ],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'X-CSRF-TOKEN',
        'X-Request-ID',
        'X-API-Version',
        'X-Client-Version',
        'HRMS-Client-ID',
        'HRMS-Client-Secret',
        'Cache-Control',
        'Pragma',
        'Origin',
        'User-Agent',
        'Referer',
    ],

    'exposed_headers' => [
        'X-Request-ID',
        'X-Response-Time',
        'X-Rate-Limit-Limit',
        'X-Rate-Limit-Remaining',
        'X-Rate-Limit-Reset',
        'X-Rate-Limit-Used',
        'Retry-After',
        'Cache-Control',
        'Content-Length',
        'Content-Type',
        'Location',
        'X-Total-Count',
    ],

    'max_age' => (int) env('CORS_MAX_AGE', 86400), // 24 hours

    'supports_credentials' => (bool) env('CORS_SUPPORTS_CREDENTIALS', true),

    /*
    |--------------------------------------------------------------------------
    | Environment-Specific CORS Configuration
    |--------------------------------------------------------------------------
    |
    | Different CORS settings for different environments
    |
    */

    'environments' => [
        'local' => [
            'allowed_origins' => [
                'http://localhost:3000',
                'http://localhost:8080',
                'http://localhost:4200',
                'http://127.0.0.1:3000',
                'http://127.0.0.1:8080',
                'http://127.0.0.1:4200',
            ],
            'allowed_origins_patterns' => [
                '/^http:\/\/localhost:\d+$/',
                '/^http:\/\/127\.0\.0\.1:\d+$/',
            ],
            'supports_credentials' => true,
        ],

        'development' => [
            'allowed_origins' => [
                'https://dev.hrms.local',
                'https://app-dev.hrms.local',
                'https://admin-dev.hrms.local',
            ],
            'allowed_origins_patterns' => [
                '/^https:\/\/.*-dev\.hrms\.local$/',
            ],
            'supports_credentials' => true,
        ],

        'staging' => [
            'allowed_origins' => [
                'https://staging.hrms.com',
                'https://app-staging.hrms.com',
                'https://admin-staging.hrms.com',
            ],
            'allowed_origins_patterns' => [
                '/^https:\/\/.*-staging\.hrms\.com$/',
            ],
            'supports_credentials' => true,
        ],

        'production' => [
            'allowed_origins' => [
                'https://hrms.com',
                'https://app.hrms.com',
                'https://admin.hrms.com',
                'https://api.hrms.com',
            ],
            'allowed_origins_patterns' => [
                '/^https:\/\/.*\.hrms\.com$/',
            ],
            'supports_credentials' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | CORS Security Settings
    |--------------------------------------------------------------------------
    |
    | Additional security settings for CORS
    |
    */

    'security' => [
        'allow_wildcard_origins' => (bool) env('CORS_ALLOW_WILDCARD', false),
        'strict_origin_check' => (bool) env('CORS_STRICT_ORIGIN_CHECK', true),
        'log_cors_violations' => (bool) env('CORS_LOG_VIOLATIONS', true),
        'block_suspicious_origins' => (bool) env('CORS_BLOCK_SUSPICIOUS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mobile App CORS Settings
    |--------------------------------------------------------------------------
    |
    | Special CORS settings for mobile applications
    |
    */

    'mobile' => [
        'allowed_origins' => [
            'capacitor://localhost',
            'ionic://localhost',
            'file://',
        ],
        'allowed_origins_patterns' => [
            '/^capacitor:\/\/localhost$/',
            '/^ionic:\/\/localhost$/',
            '/^file:\/\/.*$/',
        ],
        'supports_credentials' => false, // Mobile apps typically don't need credentials
    ],

    /*
    |--------------------------------------------------------------------------
    | Third-Party Integration CORS Settings
    |--------------------------------------------------------------------------
    |
    | CORS settings for third-party integrations
    |
    */

    'integrations' => [
        'allowed_origins' => [
            // Add specific third-party domains here
            // 'https://partner1.example.com',
            // 'https://partner2.example.com',
        ],
        'allowed_origins_patterns' => [
            // Add patterns for partner subdomains
            // '/^https:\/\/.*\.partner1\.com$/',
        ],
        'supports_credentials' => false, // Third-party integrations typically don't need credentials
    ],

    /*
    |--------------------------------------------------------------------------
    | CORS Middleware Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the CORS middleware
    |
    */

    'middleware' => [
        'enabled' => (bool) env('CORS_MIDDLEWARE_ENABLED', true),
        'priority' => (int) env('CORS_MIDDLEWARE_PRIORITY', 0),
        'handle_preflight' => (bool) env('CORS_HANDLE_PREFLIGHT', true),
        'handle_actual_request' => (bool) env('CORS_HANDLE_ACTUAL_REQUEST', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | CORS Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for CORS violation logging
    |
    */

    'logging' => [
        'enabled' => (bool) env('CORS_LOGGING_ENABLED', true),
        'log_level' => env('CORS_LOG_LEVEL', 'warning'),
        'log_channel' => env('CORS_LOG_CHANNEL', 'cors'),
        'log_violations' => (bool) env('CORS_LOG_VIOLATIONS', true),
        'log_successful_requests' => (bool) env('CORS_LOG_SUCCESSFUL', false),
    ],
];
