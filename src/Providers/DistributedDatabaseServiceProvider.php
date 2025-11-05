<?php

namespace Shared\Providers;

use Illuminate\Support\ServiceProvider;
use Shared\Middleware\DistributedTenantDatabaseMiddleware;
use Shared\Services\DistributedDatabaseService;

/**
 * Distributed Database Service Provider
 * 
 * Registers services and middleware for the distributed database architecture
 * where each microservice has its own PostgreSQL instance.
 * 
 * This provider should be used in Docker/Kubernetes environments where
 * microservices are deployed as separate containers with their own
 * database instances.
 */
class DistributedDatabaseServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register distributed database service as singleton
        $this->app->singleton(DistributedDatabaseService::class, function ($app) {
            return new DistributedDatabaseService;
        });

        // Register distributed tenant database middleware
        $this->app->singleton(DistributedTenantDatabaseMiddleware::class, function ($app) {
            return new DistributedTenantDatabaseMiddleware(
                $app->make(DistributedDatabaseService::class)
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
            __DIR__.'/../Config/database.php' => config_path('database-distributed.php'),
        ], 'distributed-database-config');

        // Register middleware alias
        $this->app['router']->aliasMiddleware(
            'tenant.distributed',
            DistributedTenantDatabaseMiddleware::class
        );

        // Merge configuration
        $this->mergeConfigFrom(
            __DIR__.'/../Config/database.php',
            'database-distributed'
        );

        // Log service provider initialization
        if (config('database-distributed.monitoring.log_connections', false)) {
            \Illuminate\Support\Facades\Log::info('Distributed Database Service Provider initialized', [
                'service' => config('app.service_name', env('SERVICE_NAME', 'unknown')),
                'docker_enabled' => config('database-distributed.docker.enabled', true),
            ]);
        }
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            DistributedDatabaseService::class,
            DistributedTenantDatabaseMiddleware::class,
        ];
    }
}

