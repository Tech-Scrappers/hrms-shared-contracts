<?php

namespace Shared\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Shared\Events\EventSubscriber;

class EventWorkerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'events:worker {--service= : Service name to process events for} {--timeout=0 : Timeout in seconds (0 for infinite)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start event worker to process events from Redis';

    private bool $shouldStop = false;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $serviceName = $this->option('service') ?: env('SERVICE_NAME', 'default');
        $timeout = (int) $this->option('timeout');
        
        $this->info("Starting event worker for service: {$serviceName}");
        
        // Set up signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleShutdown']);
            pcntl_signal(SIGINT, [$this, 'handleShutdown']);
        }
        
        $startTime = time();
        
        try {
            $eventSubscriber = app(EventSubscriber::class);
            
            // Register handlers
            $this->registerEventHandlers($eventSubscriber, $serviceName);
            
            $this->info('Event handlers registered, starting to process events...');
            
            while (!$this->shouldStop) {
                // Check for timeout
                if ($timeout > 0 && (time() - $startTime) > $timeout) {
                    $this->info('Event worker timeout reached, stopping...');
                    break;
                }
                
                // Process signals
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
                
                try {
                    // Process events from Redis queue
                    $this->processEventsFromQueue($eventSubscriber);
                    
                    // Process retry queue periodically
                    if (time() % 60 === 0) { // Every minute
                        $this->processRetryQueue($eventSubscriber);
                    }
                    
                    // Small delay to prevent excessive CPU usage
                    usleep(100000); // 100ms
                    
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
            Log::error('Fatal error in event worker command', [
                'service' => $serviceName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            $this->error("Fatal error: {$e->getMessage()}");
            return 1;
        }
        
        $this->info('Event worker stopped');
        return 0;
    }
    
    /**
     * Register event handlers based on service
     */
    private function registerEventHandlers(EventSubscriber $eventSubscriber, string $serviceName): void
    {
        // This will be handled by the EventServiceProvider in each service
        // We just need to ensure the handlers are registered
        $this->info("Event handlers should be registered by EventServiceProvider for {$serviceName}");
    }
    
    /**
     * Process events from Redis queue
     */
    private function processEventsFromQueue(EventSubscriber $eventSubscriber): void
    {
        try {
            // Get events from Redis queue
            $events = Redis::lrange('hrms_events:queue', 0, 9); // Process up to 10 events at a time
            
            if (empty($events)) {
                return;
            }
            
            foreach ($events as $eventJson) {
                if ($this->shouldStop) {
                    break;
                }
                
                try {
                    $eventData = json_decode($eventJson, true);
                    
                    if (!$eventData || !isset($eventData['event_name'])) {
                        continue;
                    }
                    
                    // Process the event
                    $this->processEvent($eventData, $eventSubscriber);
                    
                    // Remove processed event from queue
                    Redis::lrem('hrms_events:queue', 1, $eventJson);
                    
                } catch (\Exception $e) {
                    Log::error('Error processing individual event', [
                        'event' => $eventJson,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
        } catch (\Exception $e) {
            Log::error('Error processing events from queue', [
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Process a single event
     */
    private function processEvent(array $eventData, EventSubscriber $eventSubscriber): void
    {
        $eventName = $eventData['event_name'];
        $payload = $eventData['payload'] ?? [];
        $metadata = $eventData['metadata'] ?? [];
        
        Log::info('Processing event', [
            'event_name' => $eventName,
            'event_id' => $metadata['event_id'] ?? 'unknown',
            'service' => $metadata['service'] ?? 'unknown',
        ]);
        
        // Use reflection to call the appropriate handler method
        $this->callEventHandler($eventSubscriber, $eventName, $payload, $metadata);
    }
    
    /**
     * Call event handler using reflection
     */
    private function callEventHandler(EventSubscriber $eventSubscriber, string $eventName, array $payload, array $metadata): void
    {
        try {
            // Get handlers from EventSubscriber
            $reflection = new \ReflectionClass($eventSubscriber);
            $handlersProperty = $reflection->getProperty('handlers');
            $handlersProperty->setAccessible(true);
            $handlers = $handlersProperty->getValue($eventSubscriber);
            
            if (!isset($handlers[$eventName])) {
                Log::debug('No handler found for event', [
                    'event_name' => $eventName,
                ]);
                return;
            }
            
            $handler = $handlers[$eventName];
            
            if (is_callable($handler)) {
                $handler($payload, $metadata);
            } else {
                Log::warning('Handler is not callable', [
                    'event_name' => $eventName,
                    'handler' => $handler,
                ]);
            }
            
        } catch (\Exception $e) {
            Log::error('Error calling event handler', [
                'event_name' => $eventName,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Process retry queue for failed events
     */
    private function processRetryQueue(EventSubscriber $eventSubscriber): void
    {
        try {
            // Process migration retry queue
            $retryEvents = Redis::lrange('migration_retry_queue', 0, 4); // Process up to 5 retry events
            
            foreach ($retryEvents as $eventJson) {
                if ($this->shouldStop) {
                    break;
                }
                
                try {
                    $retryEvent = json_decode($eventJson, true);
                    
                    if (!$retryEvent || !isset($retryEvent['tenant']['id'])) {
                        continue;
                    }
                    
                    $currentRetryCount = $retryEvent['retry_count'] ?? 0;
                    $maxRetries = $retryEvent['max_retries'] ?? 3;
                    
                    if ($currentRetryCount >= $maxRetries) {
                        // Move to permanently failed events
                        Redis::lrem('migration_retry_queue', 1, $eventJson);
                        Redis::lpush('permanently_failed_events', $eventJson);
                        continue;
                    }
                    
                    // Retry migration events for failed services
                    $tenant = $retryEvent['tenant'];
                    $failedServices = $retryEvent['failed_services'] ?? [];
                    
                    foreach ($failedServices as $service) {
                        $migrationEvent = new \Shared\Events\TenantMigrationEvent($tenant, $service, $tenant['id']);
                        $eventBus = new \Shared\Events\EventBus($service);
                        $eventBus->publish($migrationEvent);
                    }
                    
                    // Update retry count
                    $retryEvent['retry_count'] = $currentRetryCount + 1;
                    $retryEvent['last_retry_at'] = now()->toISOString();
                    
                    // Update the event in the queue
                    Redis::lrem('migration_retry_queue', 1, $eventJson);
                    Redis::lpush('migration_retry_queue', json_encode($retryEvent));
                    
                    Log::info('Retried migration events', [
                        'tenant_id' => $tenant['id'],
                        'retry_count' => $retryEvent['retry_count'],
                        'failed_services' => $failedServices,
                    ]);
                    
                } catch (\Exception $e) {
                    Log::error('Error processing retry event', [
                        'event' => $eventJson,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
        } catch (\Exception $e) {
            Log::error('Error processing retry queue', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle shutdown signals
     */
    public function handleShutdown(): void
    {
        $this->info('Received shutdown signal, stopping event worker...');
        $this->shouldStop = true;
    }
}
