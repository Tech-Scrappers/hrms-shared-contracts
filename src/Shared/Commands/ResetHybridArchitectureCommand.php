<?php

namespace Shared\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class ResetHybridArchitectureCommand extends Command
{
    protected $signature = 'hybrid:reset 
                            {--force : Force reset without confirmation}
                            {--fresh : Use migrate:fresh --seed (recommended)}';

    protected $description = 'Drop all tenant databases and run fresh migrations with seeding for hybrid architecture';

    public function handle(): int
    {
        if (! $this->option('force')) {
            if (! $this->confirm('This will drop ALL tenant databases and reset the entire hybrid architecture. Continue?')) {
                $this->info('Operation cancelled.');

                return 0;
            }
        }

        $this->info('🔄 Resetting Hybrid Database Architecture...');

        try {
            // Step 1: Drop all tenant databases
            $this->dropAllTenantDatabases();

            // Step 2: Reset central database
            $this->resetCentralDatabase();

            // Step 3: Run central migrations
            $this->runCentralMigrations();

            // Step 4: Create hybrid tenant databases
            $this->createHybridTenantDatabases();

            // Step 5: Run service migrations and seeders
            if ($this->option('fresh')) {
                $this->runServiceFreshMigrations();
            } else {
                $this->runServiceMigrations();
                $this->runServiceSeeders();
            }

            $this->info('🎉 Hybrid Database Architecture Reset Complete!');
            $this->table(['Component', 'Status'], [
                ['Central Database', '✅ Reset'],
                ['Tenant Databases', '✅ Recreated'],
                ['Migrations', $this->option('fresh') ? '✅ Fresh Applied' : '✅ Applied'],
                ['Seeders', $this->option('fresh') ? '✅ Fresh Applied' : '✅ Applied'],
                ['API Keys', '✅ Generated via DatabaseSeeder'],
            ]);

            return 0;

        } catch (\Exception $e) {
            $this->error('❌ Reset failed: '.$e->getMessage());
            $this->error('📍 '.$e->getFile().':'.$e->getLine());

            return 1;
        }
    }

    private function dropAllTenantDatabases(): void
    {
        $this->info('🗑️  Dropping all tenant databases...');

        // Get list of all tenant databases
        $tenantDbs = DB::select("SELECT datname FROM pg_database WHERE datname LIKE 'tenant_%'");

        if (empty($tenantDbs)) {
            $this->info('  No tenant databases found');

            return;
        }

        foreach ($tenantDbs as $db) {
            $this->info("  Dropping database: {$db->datname}");
            DB::statement("DROP DATABASE IF EXISTS \"{$db->datname}\"");
        }

        // Clean up tenant users
        $this->info('🧹 Cleaning up tenant users...');
        $tenantUsers = DB::select("SELECT usename FROM pg_user WHERE usename LIKE 'tenant_%'");

        foreach ($tenantUsers as $user) {
            try {
                DB::statement("DROP USER IF EXISTS \"{$user->usename}\"");
            } catch (\Exception $e) {
                // User might not exist, continue
            }
        }
    }

    private function resetCentralDatabase(): void
    {
        $this->info('🔄 Resetting central database...');

        DB::statement('DROP SCHEMA public CASCADE');
        DB::statement('CREATE SCHEMA public');
        DB::statement('GRANT ALL ON SCHEMA public TO postgres');
        DB::statement('GRANT ALL ON SCHEMA public TO public');
    }

    private function runCentralMigrations(): void
    {
        $this->info('🔄 Running central database migrations...');
        Artisan::call('migrate', ['--force' => true]);
    }

    private function createHybridTenantDatabases(): void
    {
        $this->info('🏗️  Creating hybrid tenant databases...');

        // First, run the central seeder to create tenants
        $this->info('  Creating tenants via DatabaseSeeder...');
        Artisan::call('db:seed', ['--class' => 'TenantSeeder', '--force' => true]);

        $tenants = \App\Models\Tenant::all();
        $services = ['identity', 'employee', 'attendance'];

        foreach ($tenants as $tenant) {
            $this->info("  Creating databases for tenant: {$tenant->name}");

            foreach ($services as $service) {
                $databaseName = "tenant_{$tenant->id}_{$service}";
                $userName = "tenant_{$tenant->id}_{$service}";
                $password = hash('sha256', "tenant_{$tenant->id}_{$service}_".time());

                $this->info("    Creating {$service} database: {$databaseName}");

                // Create database
                DB::statement("CREATE DATABASE \"{$databaseName}\"");

                // Create user
                DB::statement("CREATE USER \"{$userName}\" WITH PASSWORD '{$password}'");
                DB::statement("GRANT ALL PRIVILEGES ON DATABASE \"{$databaseName}\" TO \"{$userName}\"");

                // Grant schema privileges
                $this->grantSchemaPrivileges($databaseName, $userName);
            }
        }
    }

    private function grantSchemaPrivileges(string $databaseName, string $userName): void
    {
        // Connect to the tenant database and grant privileges
        $originalConnection = config('database.default');

        config(['database.default' => 'pgsql']);
        config(['database.connections.pgsql.database' => $databaseName]);

        DB::purge('pgsql');
        DB::reconnect('pgsql');

        try {
            DB::statement("GRANT ALL ON SCHEMA public TO \"{$userName}\"");
            DB::statement("GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO \"{$userName}\"");
            DB::statement("GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO \"{$userName}\"");
            DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO \"{$userName}\"");
            DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO \"{$userName}\"");
        } finally {
            // Restore original connection
            config(['database.default' => $originalConnection]);
            config(['database.connections.pgsql.database' => 'hrms_central']);
            DB::purge('pgsql');
            DB::reconnect('pgsql');
        }
    }

    private function runServiceMigrations(): void
    {
        $this->info('🔄 Running service migrations...');

        $tenants = \App\Models\Tenant::all();
        $services = ['identity', 'employee', 'attendance'];

        foreach ($tenants as $tenant) {
            $this->info("  Running migrations for tenant: {$tenant->id}");

            foreach ($services as $service) {
                $this->info("    Running {$service} migrations...");

                $databaseName = "tenant_{$tenant->id}_{$service}";

                // Set the database for this service
                config(['database.connections.pgsql.database' => $databaseName]);
                DB::purge('pgsql');
                DB::reconnect('pgsql');

                // Run service-specific migrations
                $migrationPath = $this->getServiceMigrationPath($service);
                Artisan::call('migrate', [
                    '--path' => $migrationPath,
                    '--force' => true,
                ]);
            }
        }

        // Restore central database connection
        config(['database.connections.pgsql.database' => 'hrms_central']);
        DB::purge('pgsql');
        DB::reconnect('pgsql');
    }

    private function runServiceFreshMigrations(): void
    {
        $this->info('🔄 Running service fresh migrations with seeding...');

        $tenants = \App\Models\Tenant::all();
        $services = ['identity', 'employee', 'attendance'];

        foreach ($tenants as $tenant) {
            $this->info("  Running fresh migrations for tenant: {$tenant->id}");

            foreach ($services as $service) {
                $this->info("    Running {$service} fresh migrations...");

                $databaseName = "tenant_{$tenant->id}_{$service}";

                // Set the database for this service
                config(['database.connections.pgsql.database' => $databaseName]);
                DB::purge('pgsql');
                DB::reconnect('pgsql');

                // Run migrate:fresh --seed for the service
                Artisan::call('migrate:fresh', [
                    '--seed' => true,
                    '--force' => true,
                ]);
            }
        }

        // Restore central database connection
        config(['database.connections.pgsql.database' => 'hrms_central']);
        DB::purge('pgsql');
        DB::reconnect('pgsql');
    }

    private function runServiceSeeders(): void
    {
        $this->info('🌱 Running service seeders...');

        $tenants = \App\Models\Tenant::all();
        $services = ['identity', 'employee', 'attendance'];

        foreach ($tenants as $tenant) {
            $this->info("  Running seeders for tenant: {$tenant->id}");

            foreach ($services as $service) {
                $this->info("    Running {$service} seeders...");

                $databaseName = "tenant_{$tenant->id}_{$service}";

                // Set the database for this service
                config(['database.connections.pgsql.database' => $databaseName]);
                DB::purge('pgsql');
                DB::reconnect('pgsql');

                // Run service-specific seeders
                $seederClass = $this->getServiceSeederClass($service);
                Artisan::call('db:seed', [
                    '--class' => $seederClass,
                    '--force' => true,
                ]);
            }
        }

        // Restore central database connection
        config(['database.connections.pgsql.database' => 'hrms_central']);
        DB::purge('pgsql');
        DB::reconnect('pgsql');
    }

    private function getServiceMigrationPath(string $service): string
    {
        return match ($service) {
            'identity' => 'database/migrations/identity',
            'employee' => 'database/migrations/employee',
            'attendance' => 'database/migrations/attendance',
            default => 'database/migrations'
        };
    }

    private function getServiceSeederClass(string $service): string
    {
        return match ($service) {
            'identity' => 'DatabaseSeeder',
            'employee' => 'DatabaseSeeder',
            'attendance' => 'DatabaseSeeder',
            default => 'DatabaseSeeder'
        };
    }
}
