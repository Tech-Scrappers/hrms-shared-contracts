<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Performance Monitoring Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains configuration options for performance monitoring
    | and optimization features.
    |
    */

    'monitoring' => [
        'enabled' => env('PERFORMANCE_MONITORING_ENABLED', true),
        'log_slow_queries' => env('LOG_SLOW_QUERIES', true),
        'slow_query_threshold_ms' => env('SLOW_QUERY_THRESHOLD_MS', 1000),
    ],

    'caching' => [
        'enable_response_caching' => env('ENABLE_RESPONSE_CACHING', true),
        'max_response_size' => env('MAX_CACHE_RESPONSE_SIZE', 1024 * 1024), // 1MB
        'default_ttl' => env('CACHE_DEFAULT_TTL', 120), // 2 minutes
    ],

    'thresholds' => [
        'execution_time_ms' => env('PERF_THRESHOLD_EXECUTION_TIME_MS', 1000),
        'memory_usage_mb' => env('PERF_THRESHOLD_MEMORY_USAGE_MB', 50),
        'database_queries' => env('PERF_THRESHOLD_DATABASE_QUERIES', 50),
    ],

    'database' => [
        'enable_query_caching' => env('ENABLE_QUERY_CACHING', true),
        'query_cache_ttl' => env('QUERY_CACHE_TTL', 300), // 5 minutes
        'enable_connection_pooling' => env('ENABLE_CONNECTION_POOLING', true),
        'max_connections' => env('DB_MAX_CONNECTIONS', 100),
    ],

    'redis' => [
        'enable_clustering' => env('REDIS_CLUSTERING_ENABLED', false),
        'connection_pool_size' => env('REDIS_POOL_SIZE', 10),
        'enable_persistence' => env('REDIS_PERSISTENCE_ENABLED', true),
    ],

    'compression' => [
        'enable_gzip' => env('ENABLE_GZIP_COMPRESSION', true),
        'enable_brotli' => env('ENABLE_BROTLI_COMPRESSION', true),
        'min_compression_size' => env('MIN_COMPRESSION_SIZE', 1024), // 1KB
    ],

    'optimization' => [
        'enable_eager_loading' => env('ENABLE_EAGER_LOADING', true),
        'enable_query_optimization' => env('ENABLE_QUERY_OPTIMIZATION', true),
        'enable_index_hints' => env('ENABLE_INDEX_HINTS', true),
    ],
];
