<?php

return [
    'enabled' => env('SECURITY_ENABLED', true),
    'rate_limiting' => [
        'enabled' => env('SECURITY_RATE_LIMITING_ENABLED', true),
        'tier' => env('SECURITY_RATE_LIMITING_TIER', 'authenticated'),
        'tiers' => [
            'anonymous' => [
                'requests_per_minute' => 60,
                'requests_per_hour' => 1000,
                'burst_limit' => 10,
            ],
            'authenticated' => [
                'requests_per_minute' => 200,
                'requests_per_hour' => 5000,
                'burst_limit' => 50,
            ],
            'api_key' => [
                'requests_per_minute' => 300,
                'requests_per_hour' => 10000,
                'burst_limit' => 100,
            ],
            'premium' => [
                'requests_per_minute' => 500,
                'requests_per_hour' => 20000,
                'burst_limit' => 200,
            ],
            'internal' => [
                'requests_per_minute' => 1000,
                'requests_per_hour' => 50000,
                'burst_limit' => 500,
            ],
        ],
    ],
    'headers' => [
        'enabled' => env('SECURITY_HEADERS_ENABLED', true),
        'csp' => env('SECURITY_HEADERS_CSP', "default-src 'self'"),
        'hsts' => env('SECURITY_HEADERS_HSTS', true),
        'x_frame_options' => env('SECURITY_HEADERS_X_FRAME_OPTIONS', 'DENY'),
        'x_content_type_options' => env('SECURITY_HEADERS_X_CONTENT_TYPE_OPTIONS', 'nosniff'),
        'x_xss_protection' => env('SECURITY_HEADERS_X_XSS_PROTECTION', '1; mode=block'),
        'referrer_policy' => env('SECURITY_HEADERS_REFERRER_POLICY', 'strict-origin-when-cross-origin'),
    ],
    'input_validation' => [
        'enabled' => env('SECURITY_INPUT_VALIDATION_ENABLED', true),
        'sanitize_input' => env('SECURITY_SANITIZE_INPUT', true),
        'max_input_length' => env('SECURITY_MAX_INPUT_LENGTH', 10000),
        'allowed_tags' => env('SECURITY_ALLOWED_TAGS', ''),
        'blocked_patterns' => [
            'script',
            'javascript:',
            'vbscript:',
            'onload',
            'onerror',
            'onclick',
        ],
    ],
    'api_keys' => [
        'enabled' => env('SECURITY_API_KEYS_ENABLED', true),
        'key_length' => env('SECURITY_API_KEY_LENGTH', 64),
        'prefix' => env('SECURITY_API_KEY_PREFIX', 'ak_'),
        'expiration_days' => env('SECURITY_API_KEY_EXPIRATION_DAYS', 365),
        'max_per_tenant' => env('SECURITY_API_KEY_MAX_PER_TENANT', 10),
    ],
    'oauth2' => [
        'enabled' => env('SECURITY_OAUTH2_ENABLED', true),
        'token_validation_url' => env('SECURITY_OAUTH2_TOKEN_VALIDATION_URL'),
        'client_id' => env('SECURITY_OAUTH2_CLIENT_ID'),
        'client_secret' => env('SECURITY_OAUTH2_CLIENT_SECRET'),
        'scope_validation' => env('SECURITY_OAUTH2_SCOPE_VALIDATION', true),
    ],
    'audit' => [
        'enabled' => env('SECURITY_AUDIT_ENABLED', true),
        'log_level' => env('SECURITY_AUDIT_LOG_LEVEL', 'info'),
        'log_channel' => env('SECURITY_AUDIT_LOG_CHANNEL', 'stack'),
        'retention_days' => env('SECURITY_AUDIT_RETENTION_DAYS', 90),
    ],
];
