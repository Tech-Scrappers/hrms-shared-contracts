<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Hybrid Database Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration file defines the hybrid database architecture
    | that combines Database-per-Service with Database-per-Tenant strategies.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Database Architecture Mode
    |--------------------------------------------------------------------------
    |
    | Supported modes:
    | - 'tenant-only': Current implementation (Database-per-Tenant only)
    | - 'hybrid': Future implementation (Database-per-Service + Database-per-Tenant)
    | - 'service-only': Database-per-Service only (no tenant isolation)
    |
    */
    'mode' => env('DATABASE_ARCHITECTURE_MODE', 'tenant-only'),

    /*
    |--------------------------------------------------------------------------
    | Service Definitions
    |--------------------------------------------------------------------------
    |
    | Define all microservices and their database requirements
    |
    */
    'services' => [
        'identity' => [
            'name' => 'Identity Service',
            'database_prefix' => 'identity',
            'migration_path' => 'database/migrations/identity',
            'seeder_class' => 'IdentityServiceSeeder',
            'tables' => [
                'users',
                'api_keys',
                'oauth_access_tokens',
                'oauth_refresh_tokens',
                'oauth_clients',
                'oauth_personal_access_clients',
            ],
        ],
        'employee' => [
            'name' => 'Employee Service',
            'database_prefix' => 'employee',
            'migration_path' => 'database/migrations/employee',
            'seeder_class' => 'EmployeeServiceSeeder',
            'tables' => [
                'employees',
                'departments',
                'branches',
            ],
        ],
        'core' => [
            'name' => 'Core Service',
            'database_prefix' => 'core',
            'migration_path' => 'database/migrations/core',
            'seeder_class' => 'CoreServiceSeeder',
            'tables' => [
                'attendance_records',
                'leave_requests',
                'leave_balances',
                'work_schedules',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Naming Conventions
    |--------------------------------------------------------------------------
    |
    | Define how tenant and service databases are named
    |
    */
    'naming' => [
        'tenant_database' => 'tenant_{tenant_id}',
        'service_database' => 'tenant_{tenant_id}_{service}',
        'central_database' => 'hrms_central',
    ],

    /*
    |--------------------------------------------------------------------------
    | Connection Management
    |--------------------------------------------------------------------------
    |
    | Configuration for database connection management
    |
    */
    'connections' => [
        'central' => 'pgsql',
        'tenant_prefix' => 'tenant_',
        'service_prefix' => 'service_',
        'cache_ttl' => 3600, // 1 hour
    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Management
    |--------------------------------------------------------------------------
    |
    | Configuration for running migrations across services
    |
    */
    'migrations' => [
        'auto_run' => env('AUTO_RUN_MIGRATIONS', true),
        'force' => env('FORCE_MIGRATIONS', false),
        'timeout' => 300, // 5 minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | Seeding Management
    |--------------------------------------------------------------------------
    |
    | Configuration for running seeders across services
    |
    */
    'seeding' => [
        'auto_run' => env('AUTO_RUN_SEEDERS', true),
        'force' => env('FORCE_SEEDERS', false),
        'timeout' => 300, // 5 minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Optimization
    |--------------------------------------------------------------------------
    |
    | Configuration for database performance optimization
    |
    */
    'performance' => [
        'connection_pooling' => env('DB_CONNECTION_POOLING', true),
        'max_connections' => env('DB_MAX_CONNECTIONS', 100),
        'connection_timeout' => env('DB_CONNECTION_TIMEOUT', 30),
        'query_timeout' => env('DB_QUERY_TIMEOUT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring and Logging
    |--------------------------------------------------------------------------
    |
    | Configuration for database monitoring and logging
    |
    */
    'monitoring' => [
        'log_queries' => env('DB_LOG_QUERIES', false),
        'log_slow_queries' => env('DB_LOG_SLOW_QUERIES', true),
        'slow_query_threshold' => env('DB_SLOW_QUERY_THRESHOLD', 1000), // milliseconds
        'log_connections' => env('DB_LOG_CONNECTIONS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Configuration
    |--------------------------------------------------------------------------
    |
    | Security settings for database access
    |
    */
    'security' => [
        'encrypt_connections' => env('DB_ENCRYPT_CONNECTIONS', true),
        'ssl_mode' => env('DB_SSL_MODE', 'prefer'),
        'user_isolation' => env('DB_USER_ISOLATION', true),
        'audit_logging' => env('DB_AUDIT_LOGGING', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for database backups
    |
    */
    'backup' => [
        'enabled' => env('DB_BACKUP_ENABLED', true),
        'frequency' => env('DB_BACKUP_FREQUENCY', 'daily'),
        'retention_days' => env('DB_BACKUP_RETENTION_DAYS', 30),
        'encrypt_backups' => env('DB_ENCRYPT_BACKUPS', true),
    ],
];
