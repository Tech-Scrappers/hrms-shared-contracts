<?php

namespace Shared\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Shared\Services\HybridDatabaseService;
use Shared\Services\HybridMigrationService;

class CreateHybridTenantCommand extends Command
{
    protected $signature = 'tenant:create-hybrid 
                            {name : Tenant name}
                            {domain : Tenant domain}
                            {--services=* : Specific services to create (identity,employee,attendance)}
                            {--force : Force creation even if tenant exists}';

    protected $description = 'Create a new tenant with hybrid database architecture';

    public function __construct(
        private HybridDatabaseService $hybridDatabaseService,
        private HybridMigrationService $hybridMigrationService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $name = $this->argument('name');
            $domain = $this->argument('domain');
            $services = $this->option('services') ?: ['identity', 'employee', 'attendance'];
            $force = $this->option('force');

            $this->info("🚀 Creating hybrid tenant: {$name} ({$domain})");
            $this->info('📊 Services: '.implode(', ', $services));

            // Validate services
            $validServices = $this->hybridDatabaseService->getAllServices();
            $invalidServices = array_diff($services, $validServices);

            if (! empty($invalidServices)) {
                $this->error('❌ Invalid services: '.implode(', ', $invalidServices));
                $this->info('✅ Valid services: '.implode(', ', $validServices));

                return 1;
            }

            // Check if tenant already exists
            $existingTenant = $this->hybridDatabaseService->getTenant($domain);
            if ($existingTenant && ! $force) {
                $this->error("❌ Tenant with domain '{$domain}' already exists. Use --force to override.");

                return 1;
            }

            // Create tenant record in central database
            $tenantId = $this->createTenantRecord($name, $domain, $force);

            // Create service databases
            $this->createServiceDatabases($tenantId, $services);

            // Run migrations
            $this->runServiceMigrations($tenantId, $services);

            // Run seeders
            $this->runServiceSeeders($tenantId, $services);

            $this->info('✅ Hybrid tenant created successfully!');
            $this->table(['Property', 'Value'], [
                ['Tenant ID', $tenantId],
                ['Name', $name],
                ['Domain', $domain],
                ['Services', implode(', ', $services)],
                ['Databases Created', count($services)],
            ]);

            return 0;

        } catch (Exception $e) {
            $this->error('❌ Failed to create hybrid tenant: '.$e->getMessage());
            $this->error('📍 '.$e->getFile().':'.$e->getLine());

            return 1;
        }
    }

    private function createTenantRecord(string $name, string $domain, bool $force): string
    {
        $this->info('📝 Creating tenant record in central database...');

        if ($force) {
            // Delete existing tenant and all its databases
            $existingTenant = $this->hybridDatabaseService->getTenant($domain);
            if ($existingTenant) {
                $this->warn('🗑️  Removing existing tenant and databases...');
                $this->hybridDatabaseService->dropTenantDatabases($existingTenant);
                DB::connection('pgsql')->table('tenants')->where('domain', $domain)->delete();
            }
        }

        $tenantId = \Illuminate\Support\Str::uuid()->toString();
        $databaseName = "tenant_{$tenantId}";

        DB::connection('pgsql')->table('tenants')->insert([
            'id' => $tenantId,
            'name' => $name,
            'domain' => $domain,
            'database_name' => $databaseName,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->info("✅ Tenant record created: {$tenantId}");

        return $tenantId;
    }

    private function createServiceDatabases(string $tenantId, array $services): void
    {
        $this->info('🗄️  Creating service databases...');

        $tenant = [
            'id' => $tenantId,
            'name' => $this->argument('name'),
            'domain' => $this->argument('domain'),
        ];

        foreach ($services as $service) {
            $this->info("  📊 Creating {$service} database...");

            $databaseName = "tenant_{$tenantId}_{$service}";

            // Create database
            DB::statement("CREATE DATABASE \"{$databaseName}\"");

            // Create service-specific user
            $username = "tenant_{$tenantId}_{$service}";
            $password = $this->generateServicePassword($tenantId, $service);

            DB::statement("CREATE USER \"{$username}\" WITH PASSWORD '{$password}'");
            DB::statement("GRANT ALL PRIVILEGES ON DATABASE \"{$databaseName}\" TO \"{$username}\"");

            $this->info("    ✅ Database created: {$databaseName}");
        }
    }

    private function runServiceMigrations(string $tenantId, array $services): void
    {
        $this->info('🔄 Running service migrations...');

        $tenant = [
            'id' => $tenantId,
            'name' => $this->argument('name'),
            'domain' => $this->argument('domain'),
        ];

        foreach ($services as $service) {
            $this->info("  📊 Running {$service} migrations...");

            try {
                $this->hybridMigrationService->runServiceMigrations($tenant, $service);
                $this->info("    ✅ Migrations completed for {$service}");
            } catch (Exception $e) {
                $this->error("    ❌ Migration failed for {$service}: ".$e->getMessage());
                throw $e;
            }
        }
    }

    private function runServiceSeeders(string $tenantId, array $services): void
    {
        $this->info('🌱 Running service seeders...');

        $tenant = [
            'id' => $tenantId,
            'name' => $this->argument('name'),
            'domain' => $this->argument('domain'),
        ];

        foreach ($services as $service) {
            $this->info("  📊 Running {$service} seeders...");

            try {
                $this->hybridMigrationService->runServiceSeeders($tenant, $service);
                $this->info("    ✅ Seeders completed for {$service}");
            } catch (Exception $e) {
                $this->warn("    ⚠️  Seeder failed for {$service}: ".$e->getMessage());
                // Continue with other services even if one fails
            }
        }
    }

    private function generateServicePassword(string $tenantId, string $service): string
    {
        return hash('sha256', "tenant_{$tenantId}_{$service}_".config('app.key'));
    }
}
