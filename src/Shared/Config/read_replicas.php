<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Read Replica Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration defines read replica connections for database
    | load balancing and performance optimization.
    |
    */

    'enabled' => env('READ_REPLICAS_ENABLED', false),

    'replicas' => [
        // Primary read replica
        [
            'name' => 'read_replica_1',
            'host' => env('DB_READ_HOST_1', env('DB_HOST')),
            'port' => env('DB_READ_PORT_1', env('DB_PORT', 5432)),
            'database' => env('DB_READ_DATABASE_1', env('DB_DATABASE')),
            'username' => env('DB_READ_USERNAME_1', env('DB_USERNAME')),
            'password' => env('DB_READ_PASSWORD_1', env('DB_PASSWORD')),
            'weight' => env('DB_READ_WEIGHT_1', 1),
            'charset' => env('DB_READ_CHARSET_1', 'utf8'),
            'prefix' => env('DB_READ_PREFIX_1', ''),
            'prefix_indexes' => env('DB_READ_PREFIX_INDEXES_1', true),
            'strict' => env('DB_READ_STRICT_1', true),
            'engine' => env('DB_READ_ENGINE_1'),
            'options' => json_decode(env('DB_READ_OPTIONS_1', '{}'), true),
        ],

        // Secondary read replica
        [
            'name' => 'read_replica_2',
            'host' => env('DB_READ_HOST_2'),
            'port' => env('DB_READ_PORT_2', 5432),
            'database' => env('DB_READ_DATABASE_2'),
            'username' => env('DB_READ_USERNAME_2'),
            'password' => env('DB_READ_PASSWORD_2'),
            'weight' => env('DB_READ_WEIGHT_2', 1),
            'charset' => env('DB_READ_CHARSET_2', 'utf8'),
            'prefix' => env('DB_READ_PREFIX_2', ''),
            'prefix_indexes' => env('DB_READ_PREFIX_INDEXES_2', true),
            'strict' => env('DB_READ_STRICT_2', true),
            'engine' => env('DB_READ_ENGINE_2'),
            'options' => json_decode(env('DB_READ_OPTIONS_2', '{}'), true),
        ],

        // Tertiary read replica (optional)
        [
            'name' => 'read_replica_3',
            'host' => env('DB_READ_HOST_3'),
            'port' => env('DB_READ_PORT_3', 5432),
            'database' => env('DB_READ_DATABASE_3'),
            'username' => env('DB_READ_USERNAME_3'),
            'password' => env('DB_READ_PASSWORD_3'),
            'weight' => env('DB_READ_WEIGHT_3', 1),
            'charset' => env('DB_READ_CHARSET_3', 'utf8'),
            'prefix' => env('DB_READ_PREFIX_3', ''),
            'prefix_indexes' => env('DB_READ_PREFIX_INDEXES_3', true),
            'strict' => env('DB_READ_STRICT_3', true),
            'engine' => env('DB_READ_ENGINE_3'),
            'options' => json_decode(env('DB_READ_OPTIONS_3', '{}'), true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Health Check Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for replica health monitoring and failover.
    |
    */

    'health_check' => [
        'enabled' => env('READ_REPLICA_HEALTH_CHECK_ENABLED', true),
        'interval' => env('READ_REPLICA_HEALTH_CHECK_INTERVAL', 60), // seconds
        'timeout' => env('READ_REPLICA_HEALTH_CHECK_TIMEOUT', 5), // seconds
        'max_failures' => env('READ_REPLICA_MAX_FAILURES', 3),
        'recovery_timeout' => env('READ_REPLICA_RECOVERY_TIMEOUT', 300), // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Load Balancing Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for load balancing strategies.
    |
    */

    'load_balancing' => [
        'strategy' => env('READ_REPLICA_STRATEGY', 'weighted_round_robin'), // weighted_round_robin, least_connections, fastest_response
        'max_retries' => env('READ_REPLICA_MAX_RETRIES', 3),
        'retry_delay' => env('READ_REPLICA_RETRY_DELAY', 100), // milliseconds
        'fallback_to_write' => env('READ_REPLICA_FALLBACK_TO_WRITE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for replica monitoring and metrics.
    |
    */

    'monitoring' => [
        'enabled' => env('READ_REPLICA_MONITORING_ENABLED', true),
        'log_queries' => env('READ_REPLICA_LOG_QUERIES', false),
        'log_slow_queries' => env('READ_REPLICA_LOG_SLOW_QUERIES', true),
        'slow_query_threshold' => env('READ_REPLICA_SLOW_QUERY_THRESHOLD', 1000), // milliseconds
        'metrics_retention' => env('READ_REPLICA_METRICS_RETENTION', 24), // hours
    ],

    /*
    |--------------------------------------------------------------------------
    | Connection Pool Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for connection pooling.
    |
    */

    'connection_pool' => [
        'enabled' => env('READ_REPLICA_CONNECTION_POOL_ENABLED', true),
        'min_connections' => env('READ_REPLICA_MIN_CONNECTIONS', 2),
        'max_connections' => env('READ_REPLICA_MAX_CONNECTIONS', 10),
        'idle_timeout' => env('READ_REPLICA_IDLE_TIMEOUT', 300), // seconds
        'max_lifetime' => env('READ_REPLICA_MAX_LIFETIME', 3600), // seconds
    ],
];
