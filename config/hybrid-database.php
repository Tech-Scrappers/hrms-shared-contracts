<?php

return [
    'enabled' => env('HYBRID_DATABASE_ENABLED', true),
    'service_name' => env('HYBRID_DATABASE_SERVICE_NAME', 'default-service'),
    'central_connection' => env('DB_CONNECTION', 'pgsql'),
    'tenant_connection_prefix' => 'tenant_',
    'auto_provision' => env('HYBRID_DATABASE_AUTO_PROVISION', true),
    'connection_pooling' => env('HYBRID_DATABASE_CONNECTION_POOLING', true),
    'max_connections' => env('HYBRID_DATABASE_MAX_CONNECTIONS', 10),
    'connection_timeout' => env('HYBRID_DATABASE_CONNECTION_TIMEOUT', 30),
    'retry_attempts' => env('HYBRID_DATABASE_RETRY_ATTEMPTS', 3),
    'retry_delay' => env('HYBRID_DATABASE_RETRY_DELAY', 1000),
    'cache_tenant_connections' => env('HYBRID_DATABASE_CACHE_CONNECTIONS', true),
    'cache_ttl' => env('HYBRID_DATABASE_CACHE_TTL', 3600),
    'log_queries' => env('HYBRID_DATABASE_LOG_QUERIES', false),
    'query_log_channel' => env('HYBRID_DATABASE_QUERY_LOG_CHANNEL', 'stack'),
];
