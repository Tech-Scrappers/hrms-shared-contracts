<?php

namespace Shared\Services;

use Exception;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TenantMigrationService
{
    private HybridDatabaseService $hybridDatabaseService;

    public function __construct(HybridDatabaseService $hybridDatabaseService)
    {
        $this->hybridDatabaseService = $hybridDatabaseService;
    }

    /**
     * Run migrations for a specific tenant database
     */
    public function runTenantMigrations(array $tenant): bool
    {
        try {
            $databaseName = $tenant['database_name'];

            // Configure tenant connection
            $this->configureTenantConnection($tenant);

            // Run migrations for each service
            $this->runServiceMigrations($tenant, 'identity-service');
            $this->runServiceMigrations($tenant, 'employee-service');
            $this->runServiceMigrations($tenant, 'core-service');

            Log::info('Tenant migrations completed successfully', [
                'tenant_id' => $tenant['id'],
                'database_name' => $databaseName,
            ]);

            return true;

        } catch (Exception $e) {
            Log::error('Failed to run tenant migrations', [
                'tenant_id' => $tenant['id'],
                'database_name' => $databaseName ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Run migrations for a specific service
     */
    private function runServiceMigrations(array $tenant, string $service): void
    {
        $connectionName = "tenant_{$tenant['id']}";

        // Configure tenant connection first
        $this->configureTenantConnection($tenant);

        // Set the default connection to tenant
        Config::set('database.default', $connectionName);

        // Get migration path for the service
        $migrationPath = $this->getServiceMigrationPath($service);

        if (! is_dir($migrationPath)) {
            Log::warning("Migration path not found for service: {$service}", [
                'path' => $migrationPath,
            ]);

            return;
        }

        // Run migrations
        Artisan::call('migrate', [
            '--path' => $migrationPath,
            '--database' => $connectionName,
            '--force' => true,
        ]);

        Log::info("Migrations completed for service: {$service}", [
            'tenant_id' => $tenant['id'],
            'service' => $service,
        ]);
    }

    /**
     * Get migration path for a service
     */
    private function getServiceMigrationPath(string $service): string
    {
        // Get the correct path based on the current working directory
        $currentDir = getcwd();
        if (strpos($currentDir, 'services/identity-service') !== false) {
            // Running from identity service directory
            return "../{$service}/database/migrations";
        } else {
            // Running from root directory
            return "services/{$service}/database/migrations";
        }
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
     * Seed tenant database with initial data
     */
    public function seedTenantDatabase(array $tenant): bool
    {
        try {
            $connectionName = "tenant_{$tenant['id']}";

            // Configure tenant connection
            $this->configureTenantConnection($tenant);

            // Set the default connection to tenant
            Config::set('database.default', $connectionName);

            // Run seeders for each service
            $this->runServiceSeeders($tenant, 'identity-service');
            $this->runServiceSeeders($tenant, 'employee-service');
            $this->runServiceSeeders($tenant, 'core-service');

            Log::info('Tenant database seeded successfully', [
                'tenant_id' => $tenant['id'],
                'database_name' => $tenant['database_name'],
            ]);

            return true;

        } catch (Exception $e) {
            Log::error('Failed to seed tenant database', [
                'tenant_id' => $tenant['id'],
                'database_name' => $tenant['database_name'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Run seeders for a specific service
     */
    private function runServiceSeeders(array $tenant, string $service): void
    {
        $connectionName = "tenant_{$tenant['id']}";

        // Get seeder path for the service
        $seederPath = $this->getServiceSeederPath($service);

        if (! is_dir($seederPath)) {
            Log::warning("Seeder path not found for service: {$service}", [
                'path' => $seederPath,
            ]);

            return;
        }

        // Run seeders
        Artisan::call('db:seed', [
            '--class' => $this->getServiceSeederClass($service),
            '--database' => $connectionName,
            '--force' => true,
        ]);

        Log::info("Seeders completed for service: {$service}", [
            'tenant_id' => $tenant['id'],
            'service' => $service,
        ]);
    }

    /**
     * Get seeder path for a service
     */
    private function getServiceSeederPath(string $service): string
    {
        return base_path("services/{$service}/database/seeders");
    }

    /**
     * Get seeder class for a service
     */
    private function getServiceSeederClass(string $service): string
    {
        $serviceMap = [
            'identity-service' => 'IdentityServiceSeeder',
            'employee-service' => 'EmployeeServiceSeeder',
            'core-service' => 'CoreServiceSeeder',
        ];

        return $serviceMap[$service] ?? 'DatabaseSeeder';
    }

    /**
     * Check if tenant database has required tables
     */
    public function hasRequiredTables(array $tenant): bool
    {
        try {
            $connectionName = "tenant_{$tenant['id']}";
            $this->configureTenantConnection($tenant);

            // Check for required tables
            $requiredTables = [
                'users',
                'api_keys',
                'employees',
                'departments',
                'branches',
                'attendance_records',
                'leave_requests',
                'leave_balances',
                'work_schedules',
            ];

            foreach ($requiredTables as $table) {
                $exists = DB::connection($connectionName)
                    ->select('SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = ?)', [$table]);

                if (! $exists[0]->exists) {
                    Log::warning('Required table missing in tenant database', [
                        'tenant_id' => $tenant['id'],
                        'table' => $table,
                    ]);

                    return false;
                }
            }

            return true;

        } catch (Exception $e) {
            Log::error('Failed to check tenant database tables', [
                'tenant_id' => $tenant['id'],
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get tenant database schema status
     */
    public function getSchemaStatus(array $tenant): array
    {
        try {
            $connectionName = "tenant_{$tenant['id']}";
            $this->configureTenantConnection($tenant);

            $tables = DB::connection($connectionName)
                ->select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name");

            return [
                'tenant_id' => $tenant['id'],
                'database_name' => $tenant['database_name'],
                'tables' => array_map(fn ($table) => $table->table_name, $tables),
                'table_count' => count($tables),
                'has_required_tables' => $this->hasRequiredTables($tenant),
            ];

        } catch (Exception $e) {
            Log::error('Failed to get tenant schema status', [
                'tenant_id' => $tenant['id'],
                'error' => $e->getMessage(),
            ]);

            return [
                'tenant_id' => $tenant['id'],
                'database_name' => $tenant['database_name'],
                'tables' => [],
                'table_count' => 0,
                'has_required_tables' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
