<?php

namespace Shared\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Artisan;

/**
 * Multi-Server Database Service
 * Handles tenant database creation across multiple isolated database servers
 * 
 * This service is designed for architectures where each microservice
 * has its own PostgreSQL database server.
 */
class MultiServerDatabaseService
{
    private const IDENTITY_SERVICE = 'identity';
    private const EMPLOYEE_SERVICE = 'employee';
    private const CORE_SERVICE = 'core';

    private string $currentService;

    public function __construct()
    {
        $this->currentService = $this->detectCurrentService();
    }

    /**
     * Detect current service from environment
     */
    private function detectCurrentService(): string
    {
        $configured = Config::get('app.service_name');
        $serviceName = $configured ?: env('SERVICE_NAME', 'identity-service');

        return match ($serviceName) {
            'identity-service' => self::IDENTITY_SERVICE,
            'employee-service' => self::EMPLOYEE_SERVICE,
            'core-service' => self::CORE_SERVICE,
            default => self::IDENTITY_SERVICE
        };
    }

    /**
     * Create tenant databases across all service database servers
     * 
     * IMPORTANT: This should ONLY be called from Identity Service
     * as it needs access credentials to all database servers
     * 
     * @param array $tenant Tenant information
     * @throws Exception If not called from Identity Service or on failure
     */
    public function createTenantDatabasesAcrossServices(array $tenant): void
    {
        if ($this->currentService !== self::IDENTITY_SERVICE) {
            throw new Exception("Only Identity Service can create tenant databases across all services");
        }

        $tenantId = $tenant['id'];
        
        // Define all service database servers
        $services = [
            self::IDENTITY_SERVICE => [
                'host' => env('DB_HOST', 'identity-db'),
                'port' => env('DB_PORT', 5432),
                'username' => env('DB_USERNAME', 'postgres'),
                'password' => env('DB_PASSWORD'),
            ],
            self::EMPLOYEE_SERVICE => [
                'host' => env('EMPLOYEE_DB_HOST', 'employee-db'),
                'port' => env('EMPLOYEE_DB_PORT', 5432),
                'username' => env('EMPLOYEE_DB_USERNAME', 'postgres'),
                'password' => env('EMPLOYEE_DB_PASSWORD'),
            ],
            self::CORE_SERVICE => [
                'host' => env('CORE_DB_HOST', 'core-db'),
                'port' => env('CORE_DB_PORT', 5432),
                'username' => env('CORE_DB_USERNAME', 'postgres'),
                'password' => env('CORE_DB_PASSWORD'),
            ],
        ];

        $createdServices = [];

        foreach ($services as $service => $config) {
            try {
                $this->createTenantDatabaseOnServer($tenantId, $service, $config);
                $createdServices[] = $service;
                
                Log::info("Created tenant database on {$service} server", [
                    'tenant_id' => $tenantId,
                    'service' => $service,
                    'database' => "tenant_{$tenantId}_{$service}",
                ]);
            } catch (Exception $e) {
                Log::error("Failed to create tenant database on {$service} server", [
                    'tenant_id' => $tenantId,
                    'service' => $service,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                
                // Rollback already created databases
                $this->rollbackTenantDatabases($tenantId, $services, $createdServices);
                throw new Exception("Failed to create tenant database on {$service} server: " . $e->getMessage());
            }
        }

        Log::info("Successfully created tenant databases on all servers", [
            'tenant_id' => $tenantId,
            'services' => array_keys($services),
        ]);
    }

    /**
     * Create tenant database on a specific database server
     * 
     * @param string $tenantId Tenant UUID
     * @param string $service Service name (identity, employee, core)
     * @param array $config Database server configuration
     * @throws Exception On any failure
     */
    private function createTenantDatabaseOnServer(string $tenantId, string $service, array $config): void
    {
        $databaseName = "tenant_{$tenantId}_{$service}";
        $tempConnectionName = "temp_{$service}_admin";

        // Step 1: Configure temporary connection to the target server's postgres database
        Config::set("database.connections.{$tempConnectionName}", [
            'driver' => 'pgsql',
            'host' => $config['host'],
            'port' => $config['port'],
            'database' => 'postgres', // Connect to postgres DB to create new database
            'username' => $config['username'],
            'password' => $config['password'],
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);

        // Purge any existing connection
        DB::purge($tempConnectionName);

        try {
            // Step 2: Test connection
            $connection = DB::connection($tempConnectionName);
            $connection->select('SELECT 1');

            // Step 3: Check if database already exists
            $exists = $connection->select(
                "SELECT 1 FROM pg_database WHERE datname = ?",
                [$databaseName]
            );

            if (!empty($exists)) {
                Log::info("Tenant database already exists", [
                    'tenant_id' => $tenantId,
                    'service' => $service,
                    'database' => $databaseName,
                ]);
                return;
            }

            // Step 4: Create the database
            $connection->statement("CREATE DATABASE \"{$databaseName}\"");

            Log::info("Created tenant database", [
                'tenant_id' => $tenantId,
                'service' => $service,
                'database' => $databaseName,
                'host' => $config['host'],
            ]);

        } catch (Exception $e) {
            DB::purge($tempConnectionName);
            throw new Exception("Failed to create database on {$service} server: " . $e->getMessage());
        }

        // Step 5: Configure connection to the new tenant database
        $tenantConnectionName = "tenant_{$tenantId}_{$service}";
        Config::set("database.connections.{$tenantConnectionName}", [
            'driver' => 'pgsql',
            'host' => $config['host'],
            'port' => $config['port'],
            'database' => $databaseName,
            'username' => $config['username'],
            'password' => $config['password'],
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);

        // Purge and reconnect
        DB::purge($tenantConnectionName);

        try {
            // Step 6: Test connection to tenant database
            $tenantConnection = DB::connection($tenantConnectionName);
            $tenantConnection->select('SELECT 1');

            // Step 7: Run migrations on the tenant database
            $this->runMigrationsForTenantDatabase($tenantConnectionName, $service);

        } catch (Exception $e) {
            DB::purge($tenantConnectionName);
            throw new Exception("Failed to initialize tenant database on {$service} server: " . $e->getMessage());
        } finally {
            // Clean up temporary connections
            DB::purge($tempConnectionName);
            DB::purge($tenantConnectionName);
        }
    }

    /**
     * Run migrations for tenant database
     * 
     * Note: In separate DB architecture, migrations must be run from
     * the service that owns the database, so we can only run migrations
     * for the current service's tenant database from here.
     * 
     * Other services will run their migrations via event listeners.
     */
    private function runMigrationsForTenantDatabase(string $connectionName, string $service): void
    {
        // Only run migrations if this is for the current service
        if ($service !== $this->currentService) {
            Log::info("Skipping migrations - not current service", [
                'connection' => $connectionName,
                'target_service' => $service,
                'current_service' => $this->currentService,
            ]);
            return;
        }

        try {
            // Run migrations
            Artisan::call('migrate', [
                '--database' => $connectionName,
                '--force' => true,
            ]);

            $output = Artisan::output();
            
            Log::info("Ran migrations for tenant database", [
                'connection' => $connectionName,
                'service' => $service,
                'output' => $output,
            ]);

        } catch (Exception $e) {
            Log::error("Failed to run migrations for tenant database", [
                'connection' => $connectionName,
                'service' => $service,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Rollback tenant databases on failure
     * 
     * @param string $tenantId Tenant UUID
     * @param array $services All service configurations
     * @param array $createdServices List of services where databases were created
     */
    private function rollbackTenantDatabases(string $tenantId, array $services, array $createdServices): void
    {
        foreach ($createdServices as $service) {
            if (!isset($services[$service])) {
                continue;
            }

            try {
                $this->dropTenantDatabaseOnServer($tenantId, $service, $services[$service]);
                Log::info("Rolled back tenant database on {$service} server", [
                    'tenant_id' => $tenantId,
                    'service' => $service,
                ]);
            } catch (Exception $e) {
                Log::error("Failed to rollback tenant database on {$service} server", [
                    'tenant_id' => $tenantId,
                    'service' => $service,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Drop tenant database on a specific server
     * 
     * @param string $tenantId Tenant UUID
     * @param string $service Service name
     * @param array $config Database server configuration
     */
    private function dropTenantDatabaseOnServer(string $tenantId, string $service, array $config): void
    {
        $databaseName = "tenant_{$tenantId}_{$service}";
        $connectionName = "temp_{$service}_admin";

        Config::set("database.connections.{$connectionName}", [
            'driver' => 'pgsql',
            'host' => $config['host'],
            'port' => $config['port'],
            'database' => 'postgres',
            'username' => $config['username'],
            'password' => $config['password'],
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);

        DB::purge($connectionName);
        
        try {
            // Terminate existing connections to the database
            DB::connection($connectionName)->statement(
                "SELECT pg_terminate_backend(pg_stat_activity.pid) 
                 FROM pg_stat_activity 
                 WHERE pg_stat_activity.datname = ? 
                 AND pid <> pg_backend_pid()",
                [$databaseName]
            );

            // Drop the database
            DB::connection($connectionName)->statement(
                "DROP DATABASE IF EXISTS \"{$databaseName}\""
            );

            Log::info("Dropped tenant database", [
                'tenant_id' => $tenantId,
                'service' => $service,
                'database' => $databaseName,
            ]);

        } catch (Exception $e) {
            Log::warning("Failed to drop tenant database", [
                'tenant_id' => $tenantId,
                'service' => $service,
                'database' => $databaseName,
                'error' => $e->getMessage(),
            ]);
        } finally {
            DB::purge($connectionName);
        }
    }

    /**
     * Switch to tenant database (on current service's database server)
     * 
     * @param string $tenantId Tenant UUID
     * @throws Exception If connection fails
     */
    public function switchToTenantDatabase(string $tenantId): void
    {
        $databaseName = "tenant_{$tenantId}_{$this->currentService}";
        $connectionName = "tenant_{$tenantId}_{$this->currentService}";

        // Configure connection to tenant database on current service's server
        Config::set("database.connections.{$connectionName}", [
            'driver' => 'pgsql',
            'host' => env('DB_HOST'),
            'port' => env('DB_PORT', 5432),
            'database' => $databaseName,
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD'),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);

        // Purge existing connections
        DB::purge($connectionName);
        DB::purge('default');

        // Set as default connection
        Config::set('database.default', $connectionName);
        DB::setDefaultConnection($connectionName);

        // Verify connection
        try {
            $result = DB::connection($connectionName)->select('SELECT 1 as test');
            if (empty($result) || $result[0]->test !== 1) {
                throw new Exception("Connection test failed");
            }

            // Verify we're connected to the right database
            $dbName = DB::connection($connectionName)->select('SELECT current_database() as db')[0]->db;
            if ($dbName !== $databaseName) {
                throw new Exception("Connected to wrong database: {$dbName}, expected: {$databaseName}");
            }

            Log::debug('Switched to tenant database', [
                'tenant_id' => $tenantId,
                'service' => $this->currentService,
                'database' => $databaseName,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to switch to tenant database', [
                'tenant_id' => $tenantId,
                'service' => $this->currentService,
                'database' => $databaseName,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Switch back to central database
     */
    public function switchToCentralDatabase(): void
    {
        $centralConnection = 'pgsql';

        DB::purge($centralConnection);
        DB::purge('default');

        Config::set('database.default', $centralConnection);
        DB::setDefaultConnection($centralConnection);

        Log::debug('Switched to central database', [
            'service' => $this->currentService,
        ]);
    }

    /**
     * Get current service name
     */
    public function getCurrentService(): string
    {
        return $this->currentService;
    }

    /**
     * Get tenant from central database
     * 
     * @param string $identifier Tenant ID (UUID) or domain
     * @return array|null Tenant data or null if not found
     */
    public function getTenant(string $identifier): ?array
    {
        // Always query from current service's central database
        // Note: Each service maintains a replica of tenant table
        $this->switchToCentralDatabase();

        try {
            // Check if identifier is a UUID
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $identifier)) {
                $tenant = DB::table('tenants')->where('id', $identifier)->first();
            } else {
                // Assume it's a domain
                $tenant = DB::table('tenants')->where('domain', $identifier)->first();
            }

            return $tenant ? (array) $tenant : null;

        } catch (Exception $e) {
            Log::error('Failed to get tenant', [
                'identifier' => $identifier,
                'service' => $this->currentService,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get current connection information
     * 
     * @return array Connection details
     */
    public function getCurrentConnectionInfo(): array
    {
        try {
            $connection = DB::connection();
            return [
                'database_name' => $connection->getDatabaseName(),
                'driver' => $connection->getDriverName(),
                'host' => $connection->getConfig('host'),
                'service' => $this->currentService,
            ];
        } catch (Exception $e) {
            return [
                'error' => $e->getMessage(),
                'service' => $this->currentService,
            ];
        }
    }
}
