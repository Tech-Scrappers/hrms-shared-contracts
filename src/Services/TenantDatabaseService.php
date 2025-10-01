<?php

namespace Shared\Services;

// Removed Tenant model dependency for shared service
use Exception;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TenantDatabaseService
{
    private const CACHE_PREFIX = 'tenant_db_';

    private const CACHE_TTL = 3600; // 1 hour

    /**
     * Switch to tenant database
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

            // Check if tenant database exists
            if (! $this->tenantDatabaseExists($tenant['database_name'])) {
                throw new Exception("Tenant database does not exist: {$tenant['database_name']}");
            }

            // Configure tenant connection
            $this->configureTenantConnection($tenant);

            // Set as default connection
            Config::set('database.default', "tenant_{$tenantId}");

            // Log the switch
            Log::info('Switched to tenant database', [
                'tenant_id' => $tenantId,
                'database_name' => $tenant['database_name'],
            ]);

        } catch (Exception $e) {
            Log::error('Failed to switch to tenant database', [
                'tenant_id' => $tenantId,
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
        Config::set('database.default', 'pgsql');

        Log::info('Switched to central database');
    }

    /**
     * Get tenant by ID or domain
     */
    public function getTenant(string $identifier): ?array
    {
        $cacheKey = self::CACHE_PREFIX.$identifier;

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($identifier) {
            // Query the central database directly
            $tenant = DB::connection('pgsql')
                ->table('tenants')
                ->where('id', $identifier)
                ->orWhere('domain', $identifier)
                ->first();

            return $tenant ? (array) $tenant : null;
        });
    }

    /**
     * Create tenant database
     *
     * @throws Exception
     */
    public function createTenantDatabase(array $tenant): void
    {
        try {
            DB::beginTransaction();

            // Create database
            $this->createDatabase($tenant);

            // Configure connection
            $this->configureTenantConnection($tenant);

            // Run migrations
            $this->runTenantMigrations($tenant);

            // Create default data
            $this->createDefaultData($tenant);

            DB::commit();

            Log::info('Tenant database created successfully', [
                'tenant_id' => $tenant['id'],
                'database_name' => $tenant['database_name'],
            ]);

        } catch (Exception $e) {
            DB::rollBack();

            // Clean up database if creation failed
            $this->dropDatabase($tenant);

            Log::error('Failed to create tenant database', [
                'tenant_id' => $tenant['id'],
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Drop tenant database
     *
     * @throws Exception
     */
    public function dropTenantDatabase(array $tenant): void
    {
        try {
            $this->dropDatabase($tenant);

            // Clear cache
            Cache::forget(self::CACHE_PREFIX.$tenant['id']);
            Cache::forget(self::CACHE_PREFIX.$tenant['domain']);

            Log::info('Tenant database dropped successfully', [
                'tenant_id' => $tenant['id'],
                'database_name' => $tenant['database_name'],
            ]);

        } catch (Exception $e) {
            Log::error('Failed to drop tenant database', [
                'tenant_id' => $tenant['id'],
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Check if tenant database exists
     */
    public function tenantDatabaseExists(string $databaseName): bool
    {
        try {
            $result = DB::select('SELECT 1 FROM pg_database WHERE datname = ?', [$databaseName]);

            return ! empty($result);
        } catch (Exception $e) {
            Log::error('Failed to check if tenant database exists', [
                'database_name' => $databaseName,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get all tenant databases
     */
    public function getAllTenantDatabases(): array
    {
        try {
            $result = DB::select("
                SELECT datname 
                FROM pg_database 
                WHERE datname LIKE 'hrms_tenant_%'
                ORDER BY datname
            ");

            return array_map(fn ($row) => $row->datname, $result);
        } catch (Exception $e) {
            Log::error('Failed to get tenant databases', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Create database
     *
     * @throws Exception
     */
    private function createDatabase(array $tenant): void
    {
        $databaseName = $tenant['database_name'];

        // Create database
        DB::statement("CREATE DATABASE \"{$databaseName}\"");

        // Create user for tenant database
        $username = "tenant_{$databaseName}";
        $password = $this->generateTenantPassword($databaseName);

        DB::statement("CREATE USER \"{$username}\" WITH PASSWORD '{$password}'");
        DB::statement("GRANT ALL PRIVILEGES ON DATABASE \"{$databaseName}\" TO \"{$username}\"");

        // Connect to tenant database and grant schema privileges
        $this->configureTenantConnection($tenant);
        DB::statement("GRANT ALL ON SCHEMA public TO \"{$username}\"");
        DB::statement("GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO \"{$username}\"");
        DB::statement("GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO \"{$username}\"");
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO \"{$username}\"");
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO \"{$username}\"");

        // Switch back to central database
        $this->switchToCentralDatabase();
    }

    /**
     * Drop database
     *
     * @throws Exception
     */
    private function dropDatabase(array $tenant): void
    {
        $databaseName = $tenant['database_name'];
        $username = "tenant_{$databaseName}";

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
     * Configure tenant connection
     */
    private function configureTenantConnection(array $tenant): void
    {
        $connectionName = "tenant_{$tenant['id']}";

        Config::set("database.connections.{$connectionName}", [
            'driver' => 'pgsql',
            'host' => config('database.connections.pgsql.host'),
            'port' => config('database.connections.pgsql.port'),
            'database' => $tenant['database_name'],
            'username' => config('database.connections.pgsql.username'),
            'password' => config('database.connections.pgsql.password'),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);
    }

    /**
     * Run tenant migrations
     *
     * @throws Exception
     */
    private function runTenantMigrations(array $tenant): void
    {
        $connectionName = "tenant_{$tenant['id']}";

        // Set tenant connection
        Config::set('database.default', $connectionName);

        try {
            // Run migrations
            Artisan::call('migrate', [
                '--database' => $connectionName,
                '--force' => true,
            ]);

        } catch (Exception $e) {
            Log::error('Migration failed for tenant', [
                'tenant_id' => $tenant['id'],
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            // Reset to central database
            $this->switchToCentralDatabase();
        }
    }

    /**
     * Create default data for tenant
     */
    private function createDefaultData(array $tenant): void
    {
        $connectionName = "tenant_{$tenant['id']}";

        // Set tenant connection
        Config::set('database.default', $connectionName);

        try {
            // Run seeders
            Artisan::call('db:seed', [
                '--database' => $connectionName,
                '--force' => true,
            ]);

        } catch (Exception $e) {
            Log::warning('Failed to seed default data for tenant', [
                'tenant_id' => $tenant['id'],
                'error' => $e->getMessage(),
            ]);
        } finally {
            // Reset to central database
            $this->switchToCentralDatabase();
        }
    }

    /**
     * Generate tenant password
     */
    private function generateTenantPassword(string $databaseName): string
    {
        return hash('sha256', $databaseName.config('app.key'));
    }

    /**
     * Get tenant password
     */
    private function getTenantPassword(string $databaseName): string
    {
        return $this->generateTenantPassword($databaseName);
    }
}
