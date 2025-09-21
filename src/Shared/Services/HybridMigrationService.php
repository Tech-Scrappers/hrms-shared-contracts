<?php

namespace Shared\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Exception;

class HybridMigrationService
{
    private const IDENTITY_SERVICE = 'identity';
    private const EMPLOYEE_SERVICE = 'employee';
    private const ATTENDANCE_SERVICE = 'attendance';
    
    /**
     * Run migrations for all services for a tenant
     */
    public function runTenantMigrations(array $tenant): void
    {
        $services = [self::IDENTITY_SERVICE, self::EMPLOYEE_SERVICE, self::ATTENDANCE_SERVICE];
        
        foreach ($services as $service) {
            $this->runServiceMigrations($tenant, $service);
        }
    }
    
    /**
     * Run migrations for specific service
     */
    public function runServiceMigrations(array $tenant, string $service): void
    {
        $connectionName = "tenant_{$tenant['id']}_{$service}";
        $databaseName = "tenant_{$tenant['id']}_{$service}";
        
        // Configure service connection
        $this->configureServiceConnection($tenant, $service);
        
        try {
            // Set as default connection
            Config::set('database.default', $connectionName);
            
            // Run service-specific migrations
            $migrationPath = $this->getServiceMigrationPath($service);
            
            Artisan::call('migrate', [
                '--database' => $connectionName,
                '--path' => $migrationPath,
                '--force' => true,
            ]);
            
            Log::info('Service migrations completed', [
                'tenant_id' => $tenant['id'],
                'service' => $service,
                'database_name' => $databaseName,
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
     * Run seeders for all services for a tenant
     */
    public function runTenantSeeders(array $tenant): void
    {
        $services = [self::IDENTITY_SERVICE, self::EMPLOYEE_SERVICE, self::ATTENDANCE_SERVICE];
        
        foreach ($services as $service) {
            $this->runServiceSeeders($tenant, $service);
        }
    }
    
    /**
     * Run seeders for specific service
     */
    public function runServiceSeeders(array $tenant, string $service): void
    {
        $connectionName = "tenant_{$tenant['id']}_{$service}";
        
        // Configure service connection
        $this->configureServiceConnection($tenant, $service);
        
        try {
            // Set as default connection
            Config::set('database.default', $connectionName);
            
            // Run service-specific seeders
            $seederClass = $this->getServiceSeederClass($service);
            
            Artisan::call('db:seed', [
                '--database' => $connectionName,
                '--class' => $seederClass,
                '--force' => true,
            ]);
            
            Log::info('Service seeders completed', [
                'tenant_id' => $tenant['id'],
                'service' => $service,
                'seeder_class' => $seederClass,
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
     * Configure service connection
     */
    private function configureServiceConnection(array $tenant, string $service): void
    {
        $connectionName = "tenant_{$tenant['id']}_{$service}";
        $databaseName = "tenant_{$tenant['id']}_{$service}";
        
        Config::set("database.connections.{$connectionName}", [
            'driver' => 'pgsql',
            'host' => config('database.connections.pgsql.host'),
            'port' => config('database.connections.pgsql.port'),
            'database' => $databaseName,
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
     * Get service migration path
     */
    private function getServiceMigrationPath(string $service): string
    {
        return match($service) {
            self::IDENTITY_SERVICE => 'database/migrations/identity',
            self::EMPLOYEE_SERVICE => 'database/migrations/employee',
            self::ATTENDANCE_SERVICE => 'database/migrations/attendance',
            default => 'database/migrations'
        };
    }
    
    /**
     * Get service seeder class
     */
    private function getServiceSeederClass(string $service): string
    {
        return match($service) {
            self::IDENTITY_SERVICE => 'IdentityServiceSeeder',
            self::EMPLOYEE_SERVICE => 'EmployeeServiceSeeder',
            self::ATTENDANCE_SERVICE => 'AttendanceServiceSeeder',
            default => 'DatabaseSeeder'
        };
    }
    
    /**
     * Get all services
     */
    public function getAllServices(): array
    {
        return [self::IDENTITY_SERVICE, self::EMPLOYEE_SERVICE, self::ATTENDANCE_SERVICE];
    }
}
