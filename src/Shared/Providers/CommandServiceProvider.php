<?php

namespace Shared\Providers;

use Illuminate\Support\ServiceProvider;
use Shared\Commands\CreateHybridTenantCommand;
use Shared\Commands\EventHealthCheckCommand;
use Shared\Commands\EventWorkerCommand;
use Shared\Commands\GenerateSecureKeysCommand;
use Shared\Commands\ProcessEventsCommand;
use Shared\Commands\ResetHybridArchitectureCommand;
use Shared\Commands\RetryFailedEventsCommand;
use Shared\Commands\SecurityAuditCommand;

class CommandServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register shared commands
        $this->app->singleton(CreateHybridTenantCommand::class);
        $this->app->singleton(EventHealthCheckCommand::class);
        $this->app->singleton(EventWorkerCommand::class);
        $this->app->singleton(GenerateSecureKeysCommand::class);
        $this->app->singleton(ProcessEventsCommand::class);
        $this->app->singleton(ResetHybridArchitectureCommand::class);
        $this->app->singleton(RetryFailedEventsCommand::class);
        $this->app->singleton(SecurityAuditCommand::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CreateHybridTenantCommand::class,
                EventHealthCheckCommand::class,
                EventWorkerCommand::class,
                GenerateSecureKeysCommand::class,
                ProcessEventsCommand::class,
                ResetHybridArchitectureCommand::class,
                RetryFailedEventsCommand::class,
                SecurityAuditCommand::class,
            ]);
        }
    }
}
