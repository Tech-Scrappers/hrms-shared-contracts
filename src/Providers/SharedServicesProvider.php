<?php

namespace Shared\Providers;

use Illuminate\Support\ServiceProvider;
use Shared\Services\ApiKeyService;
use Shared\Services\HybridDatabaseService;
use Shared\Services\TenantDatabaseService;

class SharedServicesProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register ApiKeyService
        $this->app->singleton(ApiKeyService::class, function ($app) {
            return new ApiKeyService;
        });

        // Register TenantDatabaseService
        $this->app->singleton(TenantDatabaseService::class, function ($app) {
            return new TenantDatabaseService(
                $app->make(HybridDatabaseService::class)
            );
        });

        // Register HybridDatabaseService if not already registered
        if (! $this->app->bound(HybridDatabaseService::class)) {
            $this->app->singleton(HybridDatabaseService::class, function ($app) {
                return new HybridDatabaseService;
            });
        }

    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Register command service provider
        $this->app->register(CommandServiceProvider::class);
    }
}
