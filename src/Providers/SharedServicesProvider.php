<?php

namespace Shared\Providers;

use Illuminate\Support\ServiceProvider;
use Shared\Services\ApiKeyService;
use Shared\Services\DistributedDatabaseService;

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

        // Register DistributedDatabaseService (for distributed architecture)
        $this->app->singleton(DistributedDatabaseService::class, function ($app) {
            return new DistributedDatabaseService;
        });
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
