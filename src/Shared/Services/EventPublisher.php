<?php

namespace Shared\Services;

use Aws\Sqs\SqsClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Shared\Events\BaseEvent;
use Exception;

/**
 * Event Publisher Service
 * 
 * Handles publishing events to SQS queues and external webhooks
 * with retry logic, error handling, and monitoring.
 */
class EventPublisher
{
    /** @var \Aws\Sqs\SqsClient|null */
    protected $sqsClient;
    protected array $config;

    public function __construct()
    {
        $this->config = config('queue.connections.sqs', []);
        $this->sqsClient = null; // Lazy init only when needed
    }

    /**
     * Publish event to SQS queue
     */
    public function publishToSqs(BaseEvent $event): bool
    {
        try {
            // Skip if AWS SDK is not installed (e.g., during local seeding)
            if (!class_exists(\Aws\Sqs\SqsClient::class)) {
                Log::warning('SQS SDK not available; skipping SQS publish', [
                    'event_type' => $event->eventType,
                ]);
                return true;
            }

            $client = $this->getSqsClient();
            if (!$client) {
                Log::warning('SQS client not configured; skipping SQS publish', [
                    'event_type' => $event->eventType,
                ]);
                return true;
            }

            $queueUrl = $this->getQueueUrl($event->getQueueName());
            $correlationId = $this->getCorrelationId();
            
            $result = $client->sendMessage([
                'QueueUrl' => $queueUrl,
                'MessageBody' => $event->toJson(),
                'MessageAttributes' => [
                    'EventType' => [
                        'DataType' => 'String',
                        'StringValue' => $event->eventType,
                    ],
                    'TenantId' => [
                        'DataType' => 'String',
                        'StringValue' => $event->tenantId,
                    ],
                    'ExternalTenantId' => [
                        'DataType' => 'String',
                        'StringValue' => $event->externalTenantId ?? '',
                    ],
                    'Service' => [
                        'DataType' => 'String',
                        'StringValue' => $event->getServiceName(),
                    ],
                    'CorrelationId' => [
                        'DataType' => 'String',
                        'StringValue' => $correlationId,
                    ],
                ],
                'MessageDeduplicationId' => $event->eventId,
                'MessageGroupId' => $event->tenantId, // For FIFO queues
            ]);

            Log::info('Event published to SQS', [
                'event_id' => $event->eventId,
                'event_type' => $event->eventType,
                'tenant_id' => $event->tenantId,
                'queue_url' => $queueUrl,
                'correlation_id' => $correlationId,
                'message_id' => $result['MessageId'],
            ]);

            return true;

        } catch (Exception $e) {
            Log::error('Failed to publish event to SQS', [
                'event_id' => $event->eventId,
                'event_type' => $event->eventType,
                'tenant_id' => $event->tenantId,
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Publish event to external webhook
     */
    public function publishToWebhook(BaseEvent $event): bool
    {
        if (!$event->shouldPublishToExternal()) {
            return true; // No external integration needed
        }

        try {
            $endpoints = $event->getExternalEndpoints();
            $webhookUrl = $endpoints['webhook_url'] ?? null;
            $correlationId = $this->getCorrelationId();

            if (!$webhookUrl) {
                Log::warning('No webhook URL configured for external tenant', [
                    'external_tenant_id' => $event->externalTenantId,
                    'event_type' => $event->eventType,
                ]);
                return false;
            }

            $response = \Http::timeout(30)
                ->retry(3, 1000)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-HRMS-Event-Type' => $event->eventType,
                    'X-HRMS-Tenant-Id' => $event->tenantId,
                    'X-HRMS-Event-Id' => $event->eventId,
                    'X-Correlation-Id' => $correlationId,
                ])
                ->post($webhookUrl, $event->toArray());

            if ($response->successful()) {
                Log::info('Event published to external webhook', [
                    'event_id' => $event->eventId,
                    'event_type' => $event->eventType,
                    'tenant_id' => $event->tenantId,
                    'webhook_url' => $webhookUrl,
                    'correlation_id' => $correlationId,
                    'status_code' => $response->status(),
                ]);
                return true;
            } else {
                Log::error('External webhook returned error', [
                    'event_id' => $event->eventId,
                    'event_type' => $event->eventType,
                    'tenant_id' => $event->tenantId,
                    'webhook_url' => $webhookUrl,
                    'correlation_id' => $correlationId,
                    'status_code' => $response->status(),
                    'response' => $response->body(),
                ]);
                return false;
            }

        } catch (Exception $e) {
            Log::error('Failed to publish event to external webhook', [
                'event_id' => $event->eventId,
                'event_type' => $event->eventType,
                'tenant_id' => $event->tenantId,
                'correlation_id' => $this->getCorrelationId(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Publish event to both SQS and external webhooks
     */
    public function publish(BaseEvent $event): bool
    {
        $sqsSuccess = $this->publishToSqs($event);
        $webhookSuccess = $this->publishToWebhook($event);

        // Store event for retry if either failed
        if (!$sqsSuccess || !$webhookSuccess) {
            $this->storeForRetry($event);
        }

        return $sqsSuccess && $webhookSuccess;
    }

    /**
     * Publish event asynchronously using Laravel queues
     */
    public function publishAsync(BaseEvent $event): void
    {
        Queue::push(\Shared\Jobs\PublishEventJob::class, [
            'event' => $event->toArray(),
            'event_class' => get_class($event),
        ]);
    }

    /**
     * Get SQS queue URL
     */
    protected function getQueueUrl(string $queueName): string
    {
        $accountId = config('aws.account_id');
        $region = $this->config['region'] ?? 'us-east-1';
        
        return "https://sqs.{$region}.amazonaws.com/{$accountId}/{$queueName}";
    }

    /**
     * Lazily build the SQS client if available.
     */
    protected function getSqsClient(): ?SqsClient
    {
        if (!class_exists(\Aws\Sqs\SqsClient::class)) {
            return null;
        }

        if ($this->sqsClient instanceof SqsClient) {
            return $this->sqsClient;
        }

        $key = $this->config['key'] ?? config('aws.key');
        $secret = $this->config['secret'] ?? config('aws.secret');
        $region = $this->config['region'] ?? config('aws.region', 'us-east-1');

        if (!$region || !$key || !$secret) {
            return null;
        }

        $this->sqsClient = new SqsClient([
            'version' => 'latest',
            'region' => $region,
            'credentials' => [
                'key' => $key,
                'secret' => $secret,
            ],
        ]);

        return $this->sqsClient;
    }

    /**
     * Store failed event for retry
     */
    protected function storeForRetry(BaseEvent $event): void
    {
        // Store in database for manual retry or dead letter queue
        \DB::table('failed_events')->insert([
            'event_id' => $event->eventId,
            'event_type' => $event->eventType,
            'tenant_id' => $event->tenantId,
            'event_data' => $event->toJson(),
            'failed_at' => now(),
            'retry_count' => 0,
            'error_message' => null,
            'error_type' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Retry failed events
     */
    public function retryFailedEvents(int $maxRetries = 3): int
    {
        $failedEvents = \DB::table('failed_events')
            ->where('retry_count', '<', $maxRetries)
            ->where('failed_at', '>', now()->subHours(24))
            ->get();

        $retried = 0;

        foreach ($failedEvents as $failedEvent) {
            try {
                $eventData = json_decode($failedEvent->event_data, true);
                $eventClass = $failedEvent->event_type;
                
                // Reconstruct event object
                $event = new $eventClass(
                    $eventData['tenant_id'],
                    $eventData['payload'],
                    $eventData['user_id'],
                    $eventData['external_tenant_id'],
                    $eventData['external_ref_no'],
                    $eventData['metadata']
                );

                if ($this->publish($event)) {
                    \DB::table('failed_events')->where('id', $failedEvent->id)->delete();
                    $retried++;
                } else {
                    \DB::table('failed_events')
                        ->where('id', $failedEvent->id)
                        ->increment('retry_count');
                }

            } catch (Exception $e) {
                Log::error('Failed to retry event', [
                    'failed_event_id' => $failedEvent->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $retried;
    }

    /**
     * Get or generate a correlation ID from the current request context
     */
    protected function getCorrelationId(): string
    {
        try {
            $rid = request()->header('X-Correlation-Id') ?? request()->header('X-Request-Id');
        } catch (\Throwable $e) {
            $rid = null;
        }
        return $rid ?: (string) Str::uuid();
    }
}
