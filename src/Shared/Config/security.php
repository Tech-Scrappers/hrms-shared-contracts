<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Security Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains security-related configuration options for the
    | HRMS microservices system.
    |
    */

    'csrf' => [
        'enabled' => env('CSRF_PROTECTION', true),
        'token_length' => env('CSRF_TOKEN_LENGTH', 40),
        'token_ttl' => env('CSRF_TOKEN_TTL', 3600),
        'excluded_paths' => [
            'health',
            'api/v1/health',
            'webhooks/*',
            'api/v1/upload/*',
        ],
    ],

    'rate_limiting' => [
        'enabled' => env('RATE_LIMIT_ENABLED', true),
        'storage' => env('RATE_LIMIT_STORAGE', 'redis'),
        'burst_limit' => env('RATE_LIMIT_BURST', 100),
        'hourly_limit' => env('RATE_LIMIT_HOURLY', 1000),
        'burst_ttl' => 60, // 1 minute
        'hourly_ttl' => 3600, // 1 hour
    ],

    'input_validation' => [
        'enabled' => env('INPUT_VALIDATION_ENABLED', true),
        'sanitization_enabled' => env('INPUT_SANITIZATION_ENABLED', true),
        'xss_protection_enabled' => env('XSS_PROTECTION_ENABLED', true),
        'max_input_length' => 10000,
        'allowed_html_tags' => '<p><br><strong><em><u><ul><ol><li><h1><h2><h3><h4><h5><h6>',
    ],

    'security_headers' => [
        'enabled' => env('SECURITY_HEADERS_ENABLED', true),
        'csp_enabled' => env('CSP_ENABLED', true),
        'hsts_enabled' => env('HSTS_ENABLED', true),
        'hsts_max_age' => 31536000, // 1 year
        'hsts_include_subdomains' => true,
        'hsts_preload' => true,
    ],

    'audit_logging' => [
        'enabled' => env('AUDIT_LOG_ENABLED', true),
        'level' => env('AUDIT_LOG_LEVEL', 'info'),
        'retention_days' => env('AUDIT_LOG_RETENTION_DAYS', 2555), // 7 years
        'sensitive_fields' => [
            'password',
            'token',
            'secret',
            'key',
            'authorization',
            'cookie',
            'session',
        ],
    ],

    'database' => [
        'ssl_require' => env('DB_SSL_REQUIRE', true),
        'ssl_verify' => env('DB_SSL_VERIFY', true),
        'connection_pooling' => env('DB_CONNECTION_POOLING', true),
        'max_connections' => env('DB_MAX_CONNECTIONS', 100),
        'query_timeout' => 30,
    ],

    'redis' => [
        'ssl_require' => env('REDIS_SSL_REQUIRE', true),
        'ssl_verify' => env('REDIS_SSL_VERIFY', true),
        'auth_required' => env('REDIS_AUTH_REQUIRED', true),
        'connection_timeout' => 5,
        'read_timeout' => 5,
        'write_timeout' => 5,
    ],

    'encryption' => [
        'key' => env('ENCRYPTION_KEY'),
        'cipher' => env('ENCRYPTION_CIPHER', 'AES-256-GCM'),
        'key_length' => 32,
    ],

    'api_keys' => [
        'length' => 64,
        'prefix' => 'hrms_',
        'expiration_days' => 365,
        'rotation_days' => 90,
    ],

    'jwt' => [
        'algorithm' => 'HS256',
        'ttl' => env('JWT_TTL', 60),
        'refresh_ttl' => env('JWT_REFRESH_TTL', 20160),
        'issuer' => env('APP_URL'),
        'audience' => env('APP_URL'),
    ],

    'cors' => [
        'allowed_origins' => explode(',', env('CORS_ORIGINS', 'https://app.hrms.local,https://admin.hrms.local')),
        'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'X-API-Key', 'X-Tenant-ID'],
        'exposed_headers' => ['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'],
        'max_age' => 86400,
        'supports_credentials' => true,
    ],

    'file_upload' => [
        'max_size' => 10485760, // 10MB
        'allowed_types' => ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'],
        'scan_for_malware' => true,
        'quarantine_suspicious' => true,
    ],

    'session' => [
        'secure' => env('SESSION_SECURE_COOKIE', true),
        'http_only' => env('SESSION_HTTP_ONLY', true),
        'same_site' => env('SESSION_SAME_SITE', 'strict'),
        'lifetime' => env('SESSION_LIFETIME', 120),
        'encrypt' => env('SESSION_ENCRYPT', true),
    ],

    'password' => [
        'min_length' => 12,
        'require_uppercase' => true,
        'require_lowercase' => true,
        'require_numbers' => true,
        'require_symbols' => true,
        'max_age_days' => 90,
        'history_count' => 12,
    ],

    'two_factor' => [
        'enabled' => true,
        'issuer' => env('APP_NAME', 'HRMS'),
        'algorithm' => 'sha1',
        'digits' => 6,
        'period' => 30,
        'window' => 1,
    ],

    'backup' => [
        'enabled' => env('BACKUP_ENABLED', true),
        'schedule' => env('BACKUP_SCHEDULE', '0 2 * * *'),
        'retention_days' => env('BACKUP_RETENTION_DAYS', 30),
        'encrypt' => true,
        'compress' => true,
    ],

    'monitoring' => [
        'enabled' => env('PROMETHEUS_ENABLED', true),
        'port' => env('PROMETHEUS_PORT', 9090),
        'metrics_path' => '/metrics',
        'health_check_interval' => env('HEALTH_CHECK_INTERVAL', 30),
        'health_check_timeout' => env('HEALTH_CHECK_TIMEOUT', 10),
    ],

    'feature_flags' => [
        'csrf_protection' => env('FEATURE_CSRF_PROTECTION', true),
        'rate_limiting' => env('FEATURE_RATE_LIMITING', true),
        'audit_logging' => env('FEATURE_AUDIT_LOGGING', true),
        'security_headers' => env('FEATURE_SECURITY_HEADERS', true),
        'input_validation' => env('FEATURE_INPUT_VALIDATION', true),
        'two_factor_auth' => env('FEATURE_TWO_FACTOR_AUTH', true),
        'api_key_rotation' => env('FEATURE_API_KEY_ROTATION', true),
    ],
];
