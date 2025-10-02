<?php

namespace Shared\Providers;

use Illuminate\Support\ServiceProvider;
use Shared\Services\HybridDatabaseService;
use Shared\Services\SecurityService;
use Shared\Services\ApiResponseService;
use Shared\Services\AuditService;
use Shared\Services\EventPublisher;
use Shared\Services\DatabaseConnectionManager;
use Shared\Services\TenantDatabaseService;

class SharedContractsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register core services
        $this->app->singleton(HybridDatabaseService::class);
        $this->app->singleton(SecurityService::class);
        $this->app->singleton(ApiResponseService::class);
        $this->app->singleton(AuditService::class);
        $this->app->singleton(EventPublisher::class);
        $this->app->singleton(DatabaseConnectionManager::class);
        $this->app->singleton(TenantDatabaseService::class);

        // Register configuration files
        $this->registerConfig();
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish configuration files only when running in console
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/hybrid-database.php' => config_path('hybrid-database.php'),
                __DIR__ . '/../../config/security.php' => config_path('security.php'),
                __DIR__ . '/../../config/performance.php' => config_path('performance.php'),
                __DIR__ . '/../../config/cors.php' => config_path('cors.php'),
            ], 'hrms-shared-config');
        }
    }

    /**
     * Register configuration files
     */
    private function registerConfig(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/hybrid-database.php', 'hybrid-database');
        $this->mergeConfigFrom(__DIR__ . '/../../config/security.php', 'security');
        $this->mergeConfigFrom(__DIR__ . '/../../config/performance.php', 'performance');
        $this->mergeConfigFrom(__DIR__ . '/../../config/cors.php', 'cors');
    }
}
