<?php

namespace Shared\Providers;

use Illuminate\Support\ServiceProvider;
use Shared\Commands\EventWorkerCommand;
use Shared\Commands\ProcessEventsCommand;
use Shared\Commands\SecurityAuditCommand;

class CommandServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register shared commands
        $this->app->singleton(EventWorkerCommand::class);
        $this->app->singleton(ProcessEventsCommand::class);
        $this->app->singleton(SecurityAuditCommand::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                EventWorkerCommand::class,
                ProcessEventsCommand::class,
                SecurityAuditCommand::class,
            ]);
        }
    }
}
