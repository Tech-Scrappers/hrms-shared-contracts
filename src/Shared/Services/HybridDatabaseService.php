<?php

namespace Shared\Services;

use Exception;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HybridDatabaseService
{
    private const CACHE_PREFIX = 'hybrid_db_';

    private const CACHE_TTL = 3600; // 1 hour

    // Service identifiers
    private const IDENTITY_SERVICE = 'identity';

    private const EMPLOYEE_SERVICE = 'employee';

    private const CORE_SERVICE = 'core';

    // Current service context
    private string $currentService;

    public function __construct()
    {
        $this->currentService = $this->detectCurrentService();
    }

    /**
     * Detect current service based on environment or configuration
     */
    private function detectCurrentService(): string
    {
        $serviceName = env('SERVICE_NAME', 'identity');

        return match ($serviceName) {
            'identity-service' => self::IDENTITY_SERVICE,
            'employee-service' => self::EMPLOYEE_SERVICE,
            'core-service' => self::CORE_SERVICE,
            default => self::IDENTITY_SERVICE
        };
    }

    /**
     * Switch to tenant database for current service
     *
     * @throws Exception
     */
    public function switchToTenantDatabase(string $tenantId): void
    {
        try {
            // Get tenant information
            $tenant = $this->getTenant($tenantId);

            if (! $tenant) {
                throw new Exception("Tenant not found: {$tenantId}");
            }

            // Generate service-specific database name
            $databaseName = $this->generateServiceDatabaseName($tenant['id'], $this->currentService);

            // Check if tenant database exists for this service
            if (! $this->tenantServiceDatabaseExists($databaseName)) {
                throw new Exception("Tenant service database does not exist: {$databaseName}");
            }

            // Use production-ready connection manager
            DatabaseConnectionManager::switchToTenantDatabase($tenant['id'], $this->currentService);

            // Log the switch
            Log::info('Switched to tenant service database', [
                'tenant_id' => $tenantId,
                'service' => $this->currentService,
                'database_name' => $databaseName,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to switch to tenant service database', [
                'tenant_id' => $tenantId,
                'service' => $this->currentService,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Switch to central database
     */
    public function switchToCentralDatabase(): void
    {
        // Use production-ready connection manager
        DatabaseConnectionManager::switchToCentralDatabase();
    }

    /**
     * Get current service name
     */
    public function getCurrentService(): string
    {
        return $this->currentService;
    }

    /**
     * Get tenant by ID or domain
     */
    public function getTenant(string $identifier): ?array
    {
        $cacheKey = self::CACHE_PREFIX.$identifier;

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($identifier) {
            // Check if identifier is a UUID (for ID lookup)
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $identifier)) {
                $tenant = DB::connection('pgsql')
                    ->table('tenants')
                    ->where('id', $identifier)
                    ->first();
            } else {
                // Assume it's a domain for domain lookup
                $tenant = DB::connection('pgsql')
                    ->table('tenants')
                    ->where('domain', $identifier)
                    ->first();
            }

            return $tenant ? (array) $tenant : null;
        });
    }

    /**
     * Create tenant databases for all services
     */
    public function createTenantDatabases(array $tenant): void
    {
        try {
            // Create databases for all services
            $services = [self::IDENTITY_SERVICE, self::EMPLOYEE_SERVICE, self::CORE_SERVICE];
            $createdDatabases = [];

            foreach ($services as $service) {
                try {
                    $this->createServiceDatabase($tenant, $service);
                    $createdDatabases[] = $service;
                } catch (Exception $e) {
                    Log::error("Failed to create database for service: {$service}", [
                        'tenant_id' => $tenant['id'],
                        'service' => $service,
                        'error' => $e->getMessage(),
                    ]);

                    // Clean up already created databases
                    foreach ($createdDatabases as $createdService) {
                        try {
                            $this->dropServiceDatabase($tenant, $createdService);
                        } catch (Exception $cleanupError) {
                            Log::error("Failed to cleanup database for service: {$createdService}", [
                                'tenant_id' => $tenant['id'],
                                'service' => $createdService,
                                'error' => $cleanupError->getMessage(),
                            ]);
                        }
                    }

                    throw $e;
                }
            }

            Log::info('Tenant service databases created successfully', [
                'tenant_id' => $tenant['id'],
                'services' => $services,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to create tenant service databases', [
                'tenant_id' => $tenant['id'],
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Drop tenant databases for all services
     */
    public function dropTenantDatabases(array $tenant): void
    {
        try {
            $services = [self::IDENTITY_SERVICE, self::EMPLOYEE_SERVICE, self::CORE_SERVICE];

            foreach ($services as $service) {
                $this->dropServiceDatabase($tenant, $service);
            }

            // Clear cache
            Cache::forget(self::CACHE_PREFIX.$tenant['id']);
            Cache::forget(self::CACHE_PREFIX.$tenant['domain']);

            Log::info('Tenant service databases dropped successfully', [
                'tenant_id' => $tenant['id'],
                'services' => $services,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to drop tenant service databases', [
                'tenant_id' => $tenant['id'],
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Generate service-specific database name
     */
    private function generateServiceDatabaseName(string $tenantId, string $service): string
    {
        // Get tenant info to extract domain
        $tenant = $this->getTenant($tenantId);
        if (! $tenant) {
            throw new Exception("Tenant not found: {$tenantId}");
        }

        // Extract domain prefix (e.g., "acme" from "acme.hrms.local")
        $domain = $tenant['domain'];
        $domainPrefix = explode('.', $domain)[0];

        return "hrms_tenant_{$domainPrefix}";
    }

    /**
     * Create database for specific service
     */
    public function createServiceDatabase(array $tenant, string $service): void
    {
        $databaseName = $this->generateServiceDatabaseName($tenant['id'], $service);

        // Create database
        DB::statement("CREATE DATABASE \"{$databaseName}\"");

        // Create service-specific user
        $username = "tenant_{$tenant['id']}_{$service}";
        $password = $this->generateServicePassword($tenant['id'], $service);

        DB::statement("CREATE USER \"{$username}\" WITH PASSWORD '{$password}'");
        DB::statement("GRANT ALL PRIVILEGES ON DATABASE \"{$databaseName}\" TO \"{$username}\"");

        // Grant additional permissions for schema and tables
        DB::statement("GRANT ALL ON SCHEMA public TO \"{$username}\"");
        DB::statement("GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO \"{$username}\"");
        DB::statement("GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO \"{$username}\"");
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO \"{$username}\"");
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO \"{$username}\"");

        // Configure connection and run migrations
        $this->configureTenantServiceConnection($tenant, $service);
        $this->runServiceMigrations($tenant, $service);
        $this->createServiceDefaultData($tenant, $service);

        // Switch back to central database
        $this->switchToCentralDatabase();
    }

    /**
     * Drop database for specific service
     */
    public function dropServiceDatabase(array $tenant, string $service): void
    {
        $databaseName = $this->generateServiceDatabaseName($tenant['id'], $service);
        $username = "tenant_{$tenant['id']}_{$service}";

        // Drop user first
        try {
            DB::statement("DROP USER IF EXISTS \"{$username}\"");
        } catch (Exception $e) {
            // User might not exist, continue
        }

        // Drop database
        DB::statement("DROP DATABASE IF EXISTS \"{$databaseName}\"");
    }

    /**
     * Configure tenant service connection
     */
    private function configureTenantServiceConnection(array $tenant, string $service): void
    {
        $connectionName = "tenant_{$tenant['id']}_{$service}";
        $databaseName = $this->generateServiceDatabaseName($tenant['id'], $service);
        $username = "tenant_{$tenant['id']}_{$service}";
        $password = $this->generateServicePassword($tenant['id'], $service);

        Config::set("database.connections.{$connectionName}", [
            'driver' => 'pgsql',
            'host' => config('database.connections.pgsql.host'),
            'port' => config('database.connections.pgsql.port'),
            'database' => $databaseName,
            'username' => $username,
            'password' => $password,
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);
    }

    /**
     * Run migrations for specific service
     */
    private function runServiceMigrations(array $tenant, string $service): void
    {
        $connectionName = "tenant_{$tenant['id']}_{$service}";

        // Set service connection
        Config::set('database.default', $connectionName);

        try {
            // Run service-specific migrations
            Artisan::call('migrate', [
                '--database' => $connectionName,
                '--force' => true,
            ]);

        } catch (Exception $e) {
            Log::error('Service migration failed', [
                'tenant_id' => $tenant['id'],
                'service' => $service,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Create default data for specific service
     */
    private function createServiceDefaultData(array $tenant, string $service): void
    {
        $connectionName = "tenant_{$tenant['id']}_{$service}";

        // Set service connection
        Config::set('database.default', $connectionName);

        try {
            // First, create tenant record in the service database
            $this->createTenantRecordInServiceDatabase($tenant, $service);

            // Then run service-specific seeders
            $seederClass = match ($service) {
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

        } catch (Exception $e) {
            Log::error('Service seeding failed', [
                'tenant_id' => $tenant['id'],
                'service' => $service,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Create tenant record in service database
     */
    private function createTenantRecordInServiceDatabase(array $tenant, string $service): void
    {
        try {
            $connectionName = "tenant_{$tenant['id']}_{$service}";

            // Check if tenant record already exists
            $existingTenant = DB::connection($connectionName)
                ->table('tenants')
                ->where('id', $tenant['id'])
                ->first();

            if ($existingTenant) {
                Log::info('Tenant record already exists in service database', [
                    'tenant_id' => $tenant['id'],
                    'service' => $service,
                ]);

                return;
            }

            // Create tenant record
            DB::connection($connectionName)->table('tenants')->insert([
                'id' => $tenant['id'],
                'name' => $tenant['name'],
                'domain' => $tenant['domain'],
                'database_name' => $tenant['database_name'] ?? "tenant_{$tenant['id']}_{$service}",
                'is_active' => $tenant['is_active'] ?? true,
                'settings' => json_encode($tenant['settings'] ?? []),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Log::info('Tenant record created in service database', [
                'tenant_id' => $tenant['id'],
                'service' => $service,
                'tenant_name' => $tenant['name'],
            ]);

        } catch (Exception $e) {
            Log::error('Failed to create tenant record in service database', [
                'tenant_id' => $tenant['id'],
                'service' => $service,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Check if tenant service database exists
     */
    private function tenantServiceDatabaseExists(string $databaseName): bool
    {
        try {
            $result = DB::select('SELECT 1 FROM pg_database WHERE datname = ?', [$databaseName]);

            return ! empty($result);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Generate service-specific password
     */
    private function generateServicePassword(string $tenantId, string $service): string
    {
        return hash('sha256', "tenant_{$tenantId}_{$service}_".config('app.key'));
    }

    /**
     * Cleanup tenant databases on failure
     */
    private function cleanupTenantDatabases(array $tenant): void
    {
        $services = [self::IDENTITY_SERVICE, self::EMPLOYEE_SERVICE, self::ATTENDANCE_SERVICE];

        foreach ($services as $service) {
            try {
                $this->dropServiceDatabase($tenant, $service);
            } catch (Exception $e) {
                Log::warning('Failed to cleanup service database', [
                    'tenant_id' => $tenant['id'],
                    'service' => $service,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Get all services
     */
    public function getAllServices(): array
    {
        return [self::IDENTITY_SERVICE, self::EMPLOYEE_SERVICE, self::ATTENDANCE_SERVICE];
    }

    /**
     * Verify tenant connection is working
     */
    private function verifyTenantConnection(string $connectionName, string $databaseName): void
    {
        try {
            // Test the connection
            $result = DB::connection($connectionName)->select('SELECT 1 as test');
            if (empty($result) || $result[0]->test !== 1) {
                throw new Exception("Connection verification failed for {$connectionName}");
            }

            // Verify we're connected to the correct database
            $currentDb = DB::connection($connectionName)->select('SELECT current_database() as db_name');
            if (empty($currentDb) || $currentDb[0]->db_name !== $databaseName) {
                throw new Exception("Connected to wrong database. Expected: {$databaseName}, Got: ".($currentDb[0]->db_name ?? 'unknown'));
            }

        } catch (Exception $e) {
            Log::error('Tenant connection verification failed', [
                'connection_name' => $connectionName,
                'database_name' => $databaseName,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Purge all tenant connections to prevent memory leaks
     */
    private function purgeAllTenantConnections(): void
    {
        try {
            // Get all configured connections
            $connections = Config::get('database.connections', []);

            foreach ($connections as $name => $config) {
                // Only purge tenant connections
                if (str_starts_with($name, 'tenant_')) {
                    DB::purge($name);
                }
            }

            // Also purge the default connection
            DB::purge('default');

        } catch (Exception $e) {
            Log::warning('Failed to purge some tenant connections', [
                'error' => $e->getMessage(),
            ]);
            // Don't throw here as this is cleanup
        }
    }

    /**
     * Get current database connection info for debugging
     */
    public function getCurrentConnectionInfo(): array
    {
        return DatabaseConnectionManager::getCurrentConnectionInfo();
    }
}
