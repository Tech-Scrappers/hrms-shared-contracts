<?php

return [
    'enabled' => env('PERFORMANCE_ENABLED', true),
    'monitoring' => [
        'enabled' => env('PERFORMANCE_MONITORING_ENABLED', true),
        'threshold_ms' => env('PERFORMANCE_THRESHOLD_MS', 500),
        'log_slow_queries' => env('PERFORMANCE_LOG_SLOW_QUERIES', true),
        'slow_query_threshold_ms' => env('PERFORMANCE_SLOW_QUERY_THRESHOLD_MS', 1000),
    ],
    'caching' => [
        'enabled' => env('PERFORMANCE_CACHING_ENABLED', true),
        'default_ttl' => env('PERFORMANCE_CACHE_DEFAULT_TTL', 3600),
        'response_caching' => [
            'enabled' => env('PERFORMANCE_RESPONSE_CACHING_ENABLED', true),
            'ttl' => env('PERFORMANCE_RESPONSE_CACHE_TTL', 1800),
            'cacheable_paths' => [
                'health',
                'employees',
                'departments',
                'branches',
                'attendance',
                'leave-requests',
                'work-schedules',
            ],
        ],
        'query_caching' => [
            'enabled' => env('PERFORMANCE_QUERY_CACHING_ENABLED', true),
            'ttl' => env('PERFORMANCE_QUERY_CACHE_TTL', 300),
        ],
    ],
    'query_optimization' => [
        'enabled' => env('PERFORMANCE_QUERY_OPTIMIZATION_ENABLED', true),
        'eager_loading' => env('PERFORMANCE_EAGER_LOADING', true),
        'query_logging' => env('PERFORMANCE_QUERY_LOGGING', false),
        'max_query_time' => env('PERFORMANCE_MAX_QUERY_TIME', 5000),
    ],
    'memory' => [
        'limit' => env('PERFORMANCE_MEMORY_LIMIT', '256M'),
        'warning_threshold' => env('PERFORMANCE_MEMORY_WARNING_THRESHOLD', '128M'),
        'critical_threshold' => env('PERFORMANCE_MEMORY_CRITICAL_THRESHOLD', '200M'),
    ],
    'database' => [
        'connection_pooling' => env('PERFORMANCE_DB_CONNECTION_POOLING', true),
        'max_connections' => env('PERFORMANCE_DB_MAX_CONNECTIONS', 20),
        'connection_timeout' => env('PERFORMANCE_DB_CONNECTION_TIMEOUT', 30),
        'query_timeout' => env('PERFORMANCE_DB_QUERY_TIMEOUT', 30),
    ],
];
