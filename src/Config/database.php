<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Distributed Database Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration file defines the distributed database architecture
    | for HRMS microservices where each service has its own PostgreSQL instance.
    |
    | Architecture: Each microservice has its own database container/instance
    | Tenants: Each tenant gets separate databases in each service's DB instance
    | Naming: tenant_{tenantId}_{service}
    |
    */

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
        'tenant_database' => 'tenant_{tenant_id}_{service}',
        'central_database' => 'hrms_{service}',
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
        'cache_ttl' => 3600, // 1 hour
        'connection_pooling' => env('DB_CONNECTION_POOLING', true),
        'max_connections_per_service' => env('DB_MAX_CONNECTIONS', 50),
        'connection_timeout' => env('DB_CONNECTION_TIMEOUT', 30),
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
        'auto_run' => env('AUTO_RUN_SEEDERS', false),
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

    /*
    |--------------------------------------------------------------------------
    | Docker/Container Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for containerized deployments (Docker/Kubernetes)
    |
    */
    'docker' => [
        'enabled' => env('DOCKER_ENABLED', true),
        
        // Service-specific database hosts (for distributed architecture)
        'service_hosts' => [
            'identity' => env('IDENTITY_DB_HOST', env('DB_HOST', 'identity-db')),
            'employee' => env('EMPLOYEE_DB_HOST', env('DB_HOST', 'employee-db')),
            'core' => env('CORE_DB_HOST', env('DB_HOST', 'core-db')),
        ],
        
        // Service-specific database ports
        'service_ports' => [
            'identity' => env('IDENTITY_DB_PORT', env('DB_PORT', 5432)),
            'employee' => env('EMPLOYEE_DB_PORT', env('DB_PORT', 5432)),
            'core' => env('CORE_DB_PORT', env('DB_PORT', 5432)),
        ],
        
        // Health check configuration
        'health_check' => [
            'enabled' => env('DB_HEALTH_CHECK_ENABLED', true),
            'interval' => env('DB_HEALTH_CHECK_INTERVAL', 60), // seconds
            'timeout' => env('DB_HEALTH_CHECK_TIMEOUT', 5), // seconds
        ],
    ],
];

