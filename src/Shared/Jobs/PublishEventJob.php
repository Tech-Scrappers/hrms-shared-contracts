<?php

namespace Shared\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Shared\Services\EventPublisher;
use Exception;

/**
 * Publish Event Job
 * 
 * Asynchronous job for publishing events to SQS and external webhooks
 * with retry logic and error handling.
 */
class PublishEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;
    public int $backoff = 5;

    protected array $eventData;
    protected string $eventClass;

    public function __construct(array $eventData, string $eventClass)
    {
        $this->eventData = $eventData;
        $this->eventClass = $eventClass;
    }

    /**
     * Execute the job
     */
    public function handle(EventPublisher $eventPublisher): void
    {
        try {
            // Reconstruct the event object
            $event = new $this->eventClass(
                $this->eventData['tenant_id'],
                $this->eventData['payload'],
                $this->eventData['user_id'],
                $this->eventData['external_tenant_id'],
                $this->eventData['external_ref_no'],
                $this->eventData['metadata']
            );

            // Publish the event
            $success = $eventPublisher->publish($event);

            if (!$success) {
                throw new Exception('Failed to publish event to all destinations');
            }

            Log::info('Event published successfully via job', [
                'event_id' => $event->eventId,
                'event_type' => $event->eventType,
                'tenant_id' => $event->tenantId,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to publish event via job', [
                'event_data' => $this->eventData,
                'event_class' => $this->eventClass,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            throw $e; // Re-throw to trigger retry
        }
    }

    /**
     * Handle a job failure
     */
    public function failed(Exception $exception): void
    {
        Log::error('Event publishing job failed permanently', [
            'event_data' => $this->eventData,
            'event_class' => $this->eventClass,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // Store in dead letter queue or database for manual intervention
        \DB::table('dead_letter_events')->insert([
            'event_data' => json_encode($this->eventData),
            'event_class' => $this->eventClass,
            'error_message' => $exception->getMessage(),
            'failed_at' => now(),
            'created_at' => now(),
        ]);
    }

    /**
     * Get the tags that should be assigned to the job
     */
    public function tags(): array
    {
        return [
            'event_type:' . ($this->eventData['event_type'] ?? 'unknown'),
            'tenant_id:' . ($this->eventData['tenant_id'] ?? 'unknown'),
            'service:' . ($this->eventData['metadata']['service'] ?? 'unknown'),
        ];
    }
}
