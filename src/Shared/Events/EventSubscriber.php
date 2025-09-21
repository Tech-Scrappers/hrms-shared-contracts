<?php

namespace Shared\Events;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class EventSubscriber
{
    private string $serviceName;

    private array $handlers = [];

    private bool $isRunning = false;

    public function __construct(string $serviceName)
    {
        $this->serviceName = $serviceName;
    }

    /**
     * Register an event handler
     */
    public function registerHandler(string $eventName, callable $handler): void
    {
        $this->handlers[$eventName] = $handler;

        Log::info('Event handler registered', [
            'service' => $this->serviceName,
            'event_name' => $eventName,
        ]);
    }

    /**
     * Register multiple event handlers
     */
    public function registerHandlers(array $handlers): void
    {
        foreach ($handlers as $eventName => $handler) {
            $this->registerHandler($eventName, $handler);
        }
    }

    /**
     * Start listening for events
     */
    public function startListening(): void
    {
        if ($this->isRunning) {
            return;
        }

        $this->isRunning = true;

        Log::info('Event subscriber started', [
            'service' => $this->serviceName,
            'handlers' => array_keys($this->handlers),
        ]);

        Redis::subscribe(['hrms_events'], function (string $message) {
            $this->handleEvent($message);
        });
    }

    /**
     * Handle incoming events with retry mechanism
     */
    private function handleEvent(string $message): void
    {
        $this->processEventWithRetry($message);
    }

    /**
     * Process event with retry mechanism and error handling
     */
    private function processEventWithRetry(string $message, int $maxRetries = 3): void
    {
        $retryCount = 0;
        $lastException = null;

        while ($retryCount <= $maxRetries) {
            try {
                $this->processEvent($message);
                Log::info('Event processed successfully', [
                    'service' => $this->serviceName,
                    'retry_count' => $retryCount,
                ]);

                return; // Success, exit retry loop
            } catch (\Exception $e) {
                $lastException = $e;
                $retryCount++;

                Log::warning('Event processing failed, retrying', [
                    'service' => $this->serviceName,
                    'retry_count' => $retryCount,
                    'max_retries' => $maxRetries,
                    'error' => $e->getMessage(),
                    'message' => $message,
                ]);

                if ($retryCount <= $maxRetries) {
                    // Exponential backoff: 1s, 2s, 4s
                    $delay = pow(2, $retryCount - 1);
                    sleep($delay);
                }
            }
        }

        // All retries failed
        Log::error('Event processing failed after all retries', [
            'service' => $this->serviceName,
            'max_retries' => $maxRetries,
            'final_error' => $lastException ? $lastException->getMessage() : 'Unknown error',
            'message' => $message,
        ]);

        // Store failed event for manual processing
        $this->storeFailedEvent($message, $lastException);
    }

    /**
     * Process a single event
     */
    private function processEvent(string $message): void
    {
        $eventData = json_decode($message, true);

        if (! $eventData || ! isset($eventData['event_name'])) {
            throw new \InvalidArgumentException('Invalid event data received');
        }

        $eventName = $eventData['event_name'];
        $payload = $eventData['payload'] ?? [];
        $metadata = $eventData['metadata'] ?? [];

        // Skip events from the same service
        if (isset($metadata['service']) && $metadata['service'] === $this->serviceName) {
            return;
        }

        // Check if we have a handler for this event
        if (! isset($this->handlers[$eventName])) {
            Log::debug('No handler for event', [
                'service' => $this->serviceName,
                'event_name' => $eventName,
            ]);

            return;
        }

        Log::info('Processing event', [
            'service' => $this->serviceName,
            'event_name' => $eventName,
            'event_id' => $metadata['event_id'] ?? 'unknown',
        ]);

        // Execute the handler
        $handler = $this->handlers[$eventName];
        $handler($payload, $metadata);
    }

    /**
     * Store failed event for manual processing
     */
    private function storeFailedEvent(string $message, ?\Exception $exception): void
    {
        try {
            $failedEvent = [
                'service' => $this->serviceName,
                'message' => $message,
                'error' => $exception ? $exception->getMessage() : 'Unknown error',
                'failed_at' => now()->toISOString(),
                'retry_count' => 3, // Max retries reached
            ];

            Redis::lpush('failed_events', json_encode($failedEvent));

            // Keep only last 100 failed events
            Redis::ltrim('failed_events', 0, 99);

            Log::info('Failed event stored for manual processing', [
                'service' => $this->serviceName,
                'failed_event_id' => $failedEvent['failed_at'],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to store failed event', [
                'service' => $this->serviceName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Stop listening for events
     */
    public function stopListening(): void
    {
        $this->isRunning = false;

        Log::info('Event subscriber stopped', [
            'service' => $this->serviceName,
        ]);
    }

    /**
     * Get registered handlers
     */
    public function getHandlers(): array
    {
        return array_keys($this->handlers);
    }

    /**
     * Check if subscriber is running
     */
    public function isRunning(): bool
    {
        return $this->isRunning;
    }
}
