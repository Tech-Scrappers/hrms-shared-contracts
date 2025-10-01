<?php

namespace Shared\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Shared\Events\EventSubscriber;

class ProcessEventsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'events:process {--service= : Service name to process events for} {--timeout=60 : Timeout in seconds}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process events from the event bus';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $serviceName = $this->option('service') ?: env('SERVICE_NAME', 'default');
        $timeout = (int) $this->option('timeout');

        $this->info("Starting event processing for service: {$serviceName}");

        try {
            $eventSubscriber = app(EventSubscriber::class);

            // Set up signal handlers for graceful shutdown
            if (function_exists('pcntl_signal')) {
                pcntl_signal(SIGTERM, [$this, 'handleShutdown']);
                pcntl_signal(SIGINT, [$this, 'handleShutdown']);
            }

            $startTime = time();

            while (true) {
                // Check for timeout
                if (time() - $startTime > $timeout) {
                    $this->info('Event processing timeout reached, stopping...');
                    break;
                }

                // Process signals
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }

                try {
                    // Start listening for events (this is blocking)
                    $eventSubscriber->startListening();
                } catch (\Exception $e) {
                    Log::error('Error in event processing', [
                        'service' => $serviceName,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    $this->error("Error processing events: {$e->getMessage()}");

                    // Wait a bit before retrying
                    sleep(5);
                }
            }

        } catch (\Exception $e) {
            Log::error('Fatal error in event processing command', [
                'service' => $serviceName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->error("Fatal error: {$e->getMessage()}");

            return 1;
        }

        $this->info('Event processing stopped');

        return 0;
    }

    /**
     * Handle shutdown signals
     */
    public function handleShutdown(): void
    {
        $this->info('Received shutdown signal, stopping event processing...');
        exit(0);
    }
}
