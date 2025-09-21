<?php

namespace Shared\Providers;

use Illuminate\Support\ServiceProvider;
use Shared\Middleware\HybridTenantDatabaseMiddleware;
use Shared\Services\HybridDatabaseService;
use Shared\Services\HybridMigrationService;

class HybridDatabaseServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register hybrid database service
        $this->app->singleton(HybridDatabaseService::class, function ($app) {
            return new HybridDatabaseService;
        });

        // Register hybrid migration service
        $this->app->singleton(HybridMigrationService::class, function ($app) {
            return new HybridMigrationService;
        });

        // Register hybrid tenant database middleware
        $this->app->singleton(HybridTenantDatabaseMiddleware::class, function ($app) {
            return new HybridTenantDatabaseMiddleware(
                $app->make(HybridDatabaseService::class)
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish configuration
        $this->publishes([
            __DIR__.'/../Config/hybrid-database.php' => config_path('hybrid-database.php'),
        ], 'hybrid-database-config');

        // Register middleware alias
        $this->app['router']->aliasMiddleware('hybrid.tenant', HybridTenantDatabaseMiddleware::class);

        // Register configuration
        $this->mergeConfigFrom(
            __DIR__.'/../Config/hybrid-database.php',
            'hybrid-database'
        );
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            HybridDatabaseService::class,
            HybridMigrationService::class,
            HybridTenantDatabaseMiddleware::class,
        ];
    }
}
