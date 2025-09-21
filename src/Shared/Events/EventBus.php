<?php

namespace Shared\Events;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Shared\Contracts\EventInterface;

class EventBus
{
    private string $serviceName;
    
    public function __construct(string $serviceName = 'default')
    {
        $this->serviceName = $serviceName;
    }
    
    /**
     * Publish an event to the event bus with retry mechanism
     */
    public function publish(EventInterface $event): void
    {
        $this->publishWithRetry($event, 3);
    }

    /**
     * Publish event with retry mechanism
     */
    private function publishWithRetry(EventInterface $event, int $maxRetries = 3): void
    {
        $retryCount = 0;
        $lastException = null;

        while ($retryCount <= $maxRetries) {
            try {
                $this->doPublish($event);
                Log::info('Event published successfully', [
                    'event_name' => $event->getEventName(),
                    'service' => $this->serviceName,
                    'retry_count' => $retryCount,
                ]);
                return; // Success, exit retry loop
            } catch (\Exception $e) {
                $lastException = $e;
                $retryCount++;
                
                Log::warning('Event publishing failed, retrying', [
                    'event_name' => $event->getEventName(),
                    'service' => $this->serviceName,
                    'retry_count' => $retryCount,
                    'max_retries' => $maxRetries,
                    'error' => $e->getMessage(),
                ]);

                if ($retryCount <= $maxRetries) {
                    // Exponential backoff: 1s, 2s, 4s
                    $delay = pow(2, $retryCount - 1);
                    sleep($delay);
                }
            }
        }

        // All retries failed
        Log::error('Event publishing failed after all retries', [
            'event_name' => $event->getEventName(),
            'service' => $this->serviceName,
            'max_retries' => $maxRetries,
            'final_error' => $lastException ? $lastException->getMessage() : 'Unknown error',
        ]);

        // Store failed event for manual processing
        $this->storeFailedEvent($event, $lastException);
        
        throw $lastException;
    }

    /**
     * Actually publish the event
     */
    private function doPublish(EventInterface $event): void
    {
        $eventData = [
            'event_name' => $event->getEventName(),
            'payload' => $event->getPayload(),
            'metadata' => [
                'service' => $this->serviceName,
                'timestamp' => now()->toISOString(),
                'event_id' => uniqid('evt_', true),
                'tenant_id' => $event->getTenantId(),
            ],
        ];
        
        // Publish to Redis channel
        Redis::publish('hrms_events', json_encode($eventData));
        
        // Also store in Redis for persistence
        Redis::lpush('hrms_events:queue', json_encode($eventData));
        
        // Keep only last 1000 events in queue
        Redis::ltrim('hrms_events:queue', 0, 999);
    }

    /**
     * Store failed event for manual processing
     */
    private function storeFailedEvent(EventInterface $event, ?\Exception $exception): void
    {
        try {
            $failedEvent = [
                'event_name' => $event->getEventName(),
                'payload' => $event->getPayload(),
                'metadata' => [
                    'service' => $this->serviceName,
                    'timestamp' => now()->toISOString(),
                    'event_id' => uniqid('evt_', true),
                    'tenant_id' => $event->getTenantId(),
                ],
                'error' => $exception ? $exception->getMessage() : 'Unknown error',
                'failed_at' => now()->toISOString(),
                'retry_count' => 3, // Max retries reached
            ];

            Redis::lpush('failed_publish_events', json_encode($failedEvent));
            
            // Keep only last 50 failed events
            Redis::ltrim('failed_publish_events', 0, 49);
            
            Log::info('Failed event stored for manual processing', [
                'service' => $this->serviceName,
                'event_name' => $event->getEventName(),
                'failed_event_id' => $failedEvent['metadata']['event_id'],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to store failed event for manual processing', [
                'service' => $this->serviceName,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Subscribe to events
     */
    public function subscribe(string $eventName, callable $callback): void
    {
        Redis::subscribe(['hrms_events'], function (string $message) use ($eventName, $callback) {
            try {
                $eventData = json_decode($message, true);
                
                if (!$eventData || !isset($eventData['event_name'])) {
                    return;
                }
                
                // Check if this is the event we're interested in
                if ($eventData['event_name'] === $eventName) {
                    $callback($eventData['payload'], $eventData['metadata']);
                }
                
            } catch (\Exception $e) {
                Log::error('Failed to process event', [
                    'event_name' => $eventName,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
    
    /**
     * Subscribe to multiple events
     */
    public function subscribeToMultiple(array $eventNames, callable $callback): void
    {
        Redis::subscribe(['hrms_events'], function (string $message) use ($eventNames, $callback) {
            try {
                $eventData = json_decode($message, true);
                
                if (!$eventData || !isset($eventData['event_name'])) {
                    return;
                }
                
                // Check if this is one of the events we're interested in
                if (in_array($eventData['event_name'], $eventNames)) {
                    $callback($eventData['event_name'], $eventData['payload'], $eventData['metadata']);
                }
                
            } catch (\Exception $e) {
                Log::error('Failed to process event', [
                    'event_names' => $eventNames,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
    
    /**
     * Get event history
     */
    public function getEventHistory(int $limit = 100): array
    {
        $events = Redis::lrange('hrms_events:queue', 0, $limit - 1);
        
        return array_map(function ($event) {
            return json_decode($event, true);
        }, $events);
    }
    
    /**
     * Clear event history
     */
    public function clearEventHistory(): void
    {
        Redis::del('hrms_events:queue');
    }
    
    /**
     * Get service-specific events
     */
    public function getServiceEvents(string $serviceName, int $limit = 100): array
    {
        $allEvents = $this->getEventHistory($limit);
        
        return array_filter($allEvents, function ($event) use ($serviceName) {
            return isset($event['metadata']['service']) && $event['metadata']['service'] === $serviceName;
        });
    }
}