<?php

namespace Shared\Services;

use Exception;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Distributed Database Service for Dockerized Microservices
 * 
 * This service handles tenant database management in a fully distributed
 * architecture where each microservice has its own PostgreSQL instance.
 * 
 * Key Features:
 * - Each service manages its own database instance
 * - Tenant databases are created on each service's own DB instance
 * - Docker-aware connection handling
 * - Event-driven cross-service tenant provisioning
 * - Production-ready with connection pooling and error handling
 */
class DistributedDatabaseService
{
    private const CACHE_PREFIX = 'distributed_db_';
    private const CACHE_TTL = 3600; // 1 hour

    // Service identifiers
    private const IDENTITY_SERVICE = 'identity';
    private const EMPLOYEE_SERVICE = 'employee';
    private const CORE_SERVICE = 'core';

    // Current service context
    private string $currentService;

    // Connection pool for managing database connections
    private static array $connectionPool = [];

    public function __construct()
    {
        $this->currentService = $this->detectCurrentService();
    }

    /**
     * Detect current service based on environment or configuration
     */
    private function detectCurrentService(): string
    {
        // Prefer app config (supports cached configuration); fallback to env for dev
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
     * Create tenant database on the current service's database instance
     * 
     * In distributed architecture, each service only creates databases
     * on its own PostgreSQL instance. Cross-service provisioning is
     * handled via events.
     * 
     * @param array $tenant Tenant information
     * @throws Exception On any failure
     */
    public function createTenantDatabase(array $tenant): void
    {
        $tenantId = $tenant['id'];
        $databaseName = $this->generateDatabaseName($tenantId, $this->currentService);

        try {
            Log::info('Creating tenant database on distributed service', [
                'tenant_id' => $tenantId,
                'service' => $this->currentService,
                'database_name' => $databaseName,
            ]);

            // Step 1: Check if database already exists
            if ($this->tenantDatabaseExists($databaseName)) {
                Log::info('Tenant database already exists', [
                    'tenant_id' => $tenantId,
                    'service' => $this->currentService,
                    'database_name' => $databaseName,
                ]);
                return;
            }

            // Step 2: Create the database on current service's DB instance
            $this->createDatabase($databaseName);

            // Step 3: Configure connection to the new tenant database
            $this->configureTenantConnection($tenantId);

            // Step 4: Run migrations
            $this->runTenantMigrations($tenantId);

            // Step 5: Create tenant record in service database
            $this->createTenantRecord($tenant);

            // Step 6: Seed default data
            $this->seedTenantData($tenantId);

            // Step 7: Cache tenant information
            $this->cacheTenantInfo($tenant);

            Log::info('Tenant database created successfully on distributed service', [
                'tenant_id' => $tenantId,
                'service' => $this->currentService,
                'database_name' => $databaseName,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to create tenant database on distributed service', [
                'tenant_id' => $tenantId,
                'service' => $this->currentService,
                'database_name' => $databaseName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Cleanup on failure
            $this->cleanupFailedDatabase($databaseName);

            throw $e;
        } finally {
            // Always switch back to central database
            $this->switchToCentralDatabase();
        }
    }

    /**
     * Drop tenant database from current service's database instance
     * 
     * @param array $tenant Tenant information
     * @throws Exception On failure
     */
    public function dropTenantDatabase(array $tenant): void
    {
        $tenantId = $tenant['id'];
        $databaseName = $this->generateDatabaseName($tenantId, $this->currentService);

        try {
            Log::info('Dropping tenant database from distributed service', [
                'tenant_id' => $tenantId,
                'service' => $this->currentService,
                'database_name' => $databaseName,
            ]);

            // Terminate all connections to the database
            $this->terminateConnections($databaseName);

            // Drop the database
            DB::statement("DROP DATABASE IF EXISTS \"{$databaseName}\"");

            // Clear cache
            Cache::forget(self::CACHE_PREFIX . $tenantId);
            Cache::forget(self::CACHE_PREFIX . $tenant['domain']);

            // Clear from connection pool
            unset(self::$connectionPool["tenant_{$tenantId}_{$this->currentService}"]);

            Log::info('Tenant database dropped successfully from distributed service', [
                'tenant_id' => $tenantId,
                'service' => $this->currentService,
                'database_name' => $databaseName,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to drop tenant database from distributed service', [
                'tenant_id' => $tenantId,
                'service' => $this->currentService,
                'database_name' => $databaseName,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Switch to tenant database on current service's instance
     * 
     * @param string $tenantId Tenant UUID
     * @throws Exception If tenant not found or connection fails
     */
    public function switchToTenantDatabase(string $tenantId): void
    {
        try {
            // Get tenant information
            $tenant = $this->getTenant($tenantId);

            if (!$tenant) {
                throw new Exception("Tenant not found: {$tenantId}");
            }

            $databaseName = $this->generateDatabaseName($tenantId, $this->currentService);

            // Check if tenant database exists
            if (!$this->tenantDatabaseExists($databaseName)) {
                throw new Exception("Tenant database does not exist: {$databaseName}");
            }

            // Sanitize tenant ID for connection name (replace hyphens with underscores)
            $sanitizedTenantId = str_replace('-', '_', $tenantId);
            $connectionName = "tenant_{$sanitizedTenantId}_{$this->currentService}";

            // Check if connection exists in pool and is valid
            if (isset(self::$connectionPool[$connectionName])) {
                if ($this->isConnectionValid($connectionName)) {
                    $this->setActiveConnection($connectionName, $databaseName);
                    return;
                } else {
                    // Remove invalid connection
                    unset(self::$connectionPool[$connectionName]);
                }
            }

            // Create new connection
            $this->configureTenantConnection($tenantId);
            $this->setActiveConnection($connectionName, $databaseName);

            // Add to connection pool
            self::$connectionPool[$connectionName] = [
                'tenant_id' => $tenantId,
                'database_name' => $databaseName,
                'service' => $this->currentService,
                'created_at' => now(),
                'last_used' => now(),
            ];

            Log::debug('Switched to tenant database on distributed service', [
                'tenant_id' => $tenantId,
                'service' => $this->currentService,
                'database_name' => $databaseName,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to switch to tenant database on distributed service', [
                'tenant_id' => $tenantId,
                'service' => $this->currentService,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Switch back to central/default database
     */
    public function switchToCentralDatabase(): void
    {
        try {
            $centralConnection = $this->getCentralConnectionName();

            // Purge tenant connections to prevent memory leaks
            $this->purgeTenantConnections();

            // Set central database as active
            DB::purge($centralConnection);
            DB::purge('default');

            Config::set('database.default', $centralConnection);
            DB::setDefaultConnection($centralConnection);

            // Verify connection
            $this->verifyConnection($centralConnection);

            Log::debug('Switched to central database on distributed service', [
                'service' => $this->currentService,
                'connection' => $centralConnection,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to switch to central database on distributed service', [
                'service' => $this->currentService,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get tenant by ID or domain from central database
     * 
     * @param string $identifier Tenant ID (UUID) or domain
     * @return array|null Tenant data or null if not found
     */
    public function getTenant(string $identifier): ?array
    {
        $cacheKey = self::CACHE_PREFIX . $identifier;

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($identifier) {
            // Use TenantApiClient to fetch tenant information from Identity Service
            try {
                // Check if identifier is a UUID (for ID lookup)
                if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $identifier)) {
                    $tenant = app(\Shared\Services\TenantApiClient::class)->getTenant($identifier);
                } else {
                    // Assume it's a domain for domain lookup
                    $tenant = app(\Shared\Services\TenantApiClient::class)->getTenantByDomain($identifier);
                }

                return $tenant;

            } catch (Exception $e) {
                Log::error('Failed to get tenant from Identity Service', [
                    'identifier' => $identifier,
                    'service' => $this->currentService,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        });
    }

    /**
     * Get current service name
     */
    public function getCurrentService(): string
    {
        return $this->currentService;
    }

    /**
     * Get current connection information for debugging
     */
    public function getCurrentConnectionInfo(): array
    {
        try {
            $connection = DB::connection();

            return [
                'active_connection' => Config::get('database.default'),
                'database_name' => $connection->getDatabaseName(),
                'driver' => $connection->getDriverName(),
                'host' => $connection->getConfig('host'),
                'service' => $this->currentService,
                'pool_size' => count(self::$connectionPool),
            ];
        } catch (Exception $e) {
            return [
                'error' => $e->getMessage(),
                'service' => $this->currentService,
            ];
        }
    }

    /**
     * Check if tenant database exists on current service's instance
     */
    public function tenantDatabaseExists(string $databaseName): bool
    {
        try {
            $centralConnection = $this->getCentralConnectionName();
            $result = DB::connection($centralConnection)
                ->select('SELECT 1 FROM pg_database WHERE datname = ?', [$databaseName]);

            return !empty($result);
        } catch (Exception $e) {
            Log::error('Failed to check if tenant database exists', [
                'database_name' => $databaseName,
                'service' => $this->currentService,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Generate database name for tenant on current service
     */
    private function generateDatabaseName(string $tenantId, string $service): string
    {
        // PostgreSQL supports hyphens in database names, no need to sanitize
        // Keep the tenant ID as-is for consistency with existing databases
        return "tenant_{$tenantId}_{$service}";
    }

    /**
     * Get central connection name for current service
     */
    private function getCentralConnectionName(): string
    {
        // In distributed architecture, each service has its own "central" database
        // This is the main database of the service (not tenant-specific)
        return env('DB_CONNECTION', 'pgsql');
    }

    /**
     * Create database on current service's PostgreSQL instance
     */
    private function createDatabase(string $databaseName): void
    {
        $centralConnection = $this->getCentralConnectionName();

        DB::connection($centralConnection)
            ->statement("CREATE DATABASE \"{$databaseName}\"");

        Log::info('Database created on service instance', [
            'database_name' => $databaseName,
            'service' => $this->currentService,
        ]);
    }

    /**
     * Configure tenant connection
     */
    private function configureTenantConnection(string $tenantId): void
    {
        // Sanitize tenant ID for connection name (replace hyphens with underscores)
        $sanitizedTenantId = str_replace('-', '_', $tenantId);
        $connectionName = "tenant_{$sanitizedTenantId}_{$this->currentService}";
        $databaseName = $this->generateDatabaseName($tenantId, $this->currentService);

        // Get current service's DB configuration
        $centralConnection = $this->getCentralConnectionName();
        $centralConfig = Config::get("database.connections.{$centralConnection}");

        // Configure tenant connection using same host/port as service's central DB
        Config::set("database.connections.{$connectionName}", [
            'driver' => 'pgsql',
            'host' => $centralConfig['host'] ?? env('DB_HOST', 'localhost'),
            'port' => $centralConfig['port'] ?? env('DB_PORT', 5432),
            'database' => $databaseName,
            'username' => $centralConfig['username'] ?? env('DB_USERNAME', 'postgres'),
            'password' => $centralConfig['password'] ?? env('DB_PASSWORD'),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => $centralConfig['sslmode'] ?? 'prefer',
        ]);

        Log::debug('Tenant connection configured', [
            'connection_name' => $connectionName,
            'database_name' => $databaseName,
            'service' => $this->currentService,
            'host' => $centralConfig['host'] ?? env('DB_HOST', 'localhost'),
        ]);
    }

    /**
     * Set active database connection
     */
    private function setActiveConnection(string $connectionName, string $databaseName): void
    {
        // Purge existing connections to prevent caching issues
        DB::purge($connectionName);
        DB::purge('default');

        // Set new default connection
        Config::set('database.default', $connectionName);
        DB::setDefaultConnection($connectionName);

        // Verify connection
        $this->verifyConnection($connectionName, $databaseName);

        // Update last used time in pool
        if (isset(self::$connectionPool[$connectionName])) {
            self::$connectionPool[$connectionName]['last_used'] = now();
        }
    }

    /**
     * Verify database connection
     */
    private function verifyConnection(string $connectionName, ?string $expectedDatabase = null): void
    {
        try {
            $connection = DB::connection($connectionName);

            // Test basic connectivity
            $result = $connection->select('SELECT 1 as test');
            if (empty($result) || $result[0]->test !== 1) {
                throw new Exception("Connection test failed for {$connectionName}");
            }

            // Verify database name if provided
            if ($expectedDatabase) {
                $dbResult = $connection->select('SELECT current_database() as db_name');
                $actualDb = $dbResult[0]->db_name ?? 'unknown';

                if ($actualDb !== $expectedDatabase) {
                    throw new Exception("Connected to wrong database. Expected: {$expectedDatabase}, Got: {$actualDb}");
                }
            }

        } catch (Exception $e) {
            Log::error('Connection verification failed', [
                'connection_name' => $connectionName,
                'expected_database' => $expectedDatabase,
                'service' => $this->currentService,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Check if connection is still valid
     */
    private function isConnectionValid(string $connectionName): bool
    {
        try {
            $connection = DB::connection($connectionName);
            $connection->getPdo();
            $connection->select('SELECT 1');

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Run migrations for tenant database
     */
    private function runTenantMigrations(string $tenantId): void
    {
        $connectionName = "tenant_{$tenantId}_{$this->currentService}";

        try {
            // Run migrations on tenant database
            Artisan::call('migrate', [
                '--database' => $connectionName,
                '--force' => true,
            ]);

            $output = Artisan::output();

            Log::info('Tenant migrations completed', [
                'tenant_id' => $tenantId,
                'service' => $this->currentService,
                'connection' => $connectionName,
                'output' => $output,
            ]);

        } catch (Exception $e) {
            Log::error('Tenant migration failed', [
                'tenant_id' => $tenantId,
                'service' => $this->currentService,
                'connection' => $connectionName,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Create tenant record in service database
     */
    private function createTenantRecord(array $tenant): void
    {
        $tenantId = $tenant['id'];
        $connectionName = "tenant_{$tenantId}_{$this->currentService}";

        try {
            // Check if tenant record already exists
            $existingTenant = DB::connection($connectionName)
                ->table('tenants')
                ->where('id', $tenantId)
                ->first();

            if ($existingTenant) {
                Log::debug('Tenant record already exists in service database', [
                    'tenant_id' => $tenantId,
                    'service' => $this->currentService,
                ]);

                return;
            }

            // Create tenant record
            DB::connection($connectionName)->table('tenants')->insert([
                'id' => $tenant['id'],
                'name' => $tenant['name'],
                'domain' => $tenant['domain'],
                'database_name' => $this->generateDatabaseName($tenantId, $this->currentService),
                'is_active' => $tenant['is_active'] ?? true,
                'settings' => json_encode($tenant['settings'] ?? []),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Log::info('Tenant record created in service database', [
                'tenant_id' => $tenantId,
                'service' => $this->currentService,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to create tenant record in service database', [
                'tenant_id' => $tenantId,
                'service' => $this->currentService,
                'error' => $e->getMessage(),
            ]);

            // Don't throw - this is not critical
        }
    }

    /**
     * Seed tenant data
     */
    private function seedTenantData(string $tenantId): void
    {
        $connectionName = "tenant_{$tenantId}_{$this->currentService}";

        try {
            // Determine seeder class based on service
            $seederClass = match ($this->currentService) {
                self::IDENTITY_SERVICE => 'DatabaseSeeder',
                self::EMPLOYEE_SERVICE => 'EmployeeServiceSeeder',
                self::CORE_SERVICE => 'CoreServiceSeeder',
                default => 'DatabaseSeeder'
            };

            Artisan::call('db:seed', [
                '--database' => $connectionName,
                '--class' => $seederClass,
                '--force' => true,
            ]);

            Log::info('Tenant data seeded', [
                'tenant_id' => $tenantId,
                'service' => $this->currentService,
                'seeder' => $seederClass,
            ]);

        } catch (Exception $e) {
            Log::warning('Tenant seeding failed (non-critical)', [
                'tenant_id' => $tenantId,
                'service' => $this->currentService,
                'error' => $e->getMessage(),
            ]);

            // Don't throw - seeding failures are non-critical
        }
    }

    /**
     * Cache tenant information
     */
    private function cacheTenantInfo(array $tenant): void
    {
        Cache::put(self::CACHE_PREFIX . $tenant['id'], $tenant, self::CACHE_TTL);
        Cache::put(self::CACHE_PREFIX . $tenant['domain'], $tenant, self::CACHE_TTL);
    }

    /**
     * Terminate all connections to a database
     */
    private function terminateConnections(string $databaseName): void
    {
        try {
            $centralConnection = $this->getCentralConnectionName();

            DB::connection($centralConnection)->statement(
                "SELECT pg_terminate_backend(pg_stat_activity.pid) 
                 FROM pg_stat_activity 
                 WHERE pg_stat_activity.datname = ? 
                 AND pid <> pg_backend_pid()",
                [$databaseName]
            );

            Log::debug('Terminated connections to database', [
                'database_name' => $databaseName,
                'service' => $this->currentService,
            ]);

        } catch (Exception $e) {
            Log::warning('Failed to terminate connections', [
                'database_name' => $databaseName,
                'service' => $this->currentService,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Cleanup failed database creation
     */
    private function cleanupFailedDatabase(string $databaseName): void
    {
        try {
            $this->terminateConnections($databaseName);

            $centralConnection = $this->getCentralConnectionName();
            DB::connection($centralConnection)
                ->statement("DROP DATABASE IF EXISTS \"{$databaseName}\"");

            Log::info('Cleaned up failed database creation', [
                'database_name' => $databaseName,
                'service' => $this->currentService,
            ]);

        } catch (Exception $e) {
            Log::warning('Failed to cleanup database', [
                'database_name' => $databaseName,
                'service' => $this->currentService,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Purge tenant connections from pool
     */
    private function purgeTenantConnections(): void
    {
        foreach (self::$connectionPool as $name => $connection) {
            if (str_starts_with($name, 'tenant_')) {
                try {
                    DB::purge($name);
                    unset(self::$connectionPool[$name]);
                } catch (Exception $e) {
                    Log::warning('Failed to purge tenant connection', [
                        'connection_name' => $name,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Also purge default connection
        DB::purge('default');
    }

    /**
     * Cleanup old connections from pool
     */
    public function cleanupOldConnections(int $maxAgeMinutes = 30): void
    {
        $cutoff = now()->subMinutes($maxAgeMinutes);

        foreach (self::$connectionPool as $name => $connection) {
            if (isset($connection['last_used']) && $connection['last_used']->lt($cutoff)) {
                try {
                    DB::purge($name);
                    unset(self::$connectionPool[$name]);

                    Log::debug('Cleaned up old connection from pool', [
                        'connection_name' => $name,
                        'service' => $this->currentService,
                    ]);

                } catch (Exception $e) {
                    Log::warning('Failed to cleanup old connection', [
                        'connection_name' => $name,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Get all services
     */
    public function getAllServices(): array
    {
        return [self::IDENTITY_SERVICE, self::EMPLOYEE_SERVICE, self::CORE_SERVICE];
    }
}

