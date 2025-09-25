<?php

namespace Shared\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Base Event Class for HRMS Events
 * 
 * Provides common structure for all HRMS events with tenant awareness,
 * external service integration, and audit trail capabilities.
 */
abstract class BaseEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;
    public string $tenantId;
    public string $eventType;
    public Carbon $timestamp;
    public ?string $userId;
    public ?string $externalTenantId;
    public ?string $externalRefNo;
    public array $metadata;
    public array $payload;

    public function __construct(
        string $tenantId,
        array $payload = [],
        ?string $userId = null,
        ?string $externalTenantId = null,
        ?string $externalRefNo = null,
        array $metadata = []
    ) {
        $this->eventId = \Illuminate\Support\Str::uuid()->toString();
        $this->tenantId = $tenantId;
        $this->eventType = $this->getEventType();
        $this->timestamp = now();
        $this->userId = $userId;
        $this->externalTenantId = $externalTenantId;
        $this->externalRefNo = $externalRefNo;
        $this->metadata = array_merge([
            'service' => $this->getServiceName(),
            'version' => '1.0',
            'environment' => config('app.env', 'local'),
        ], $metadata);
        $this->payload = $payload;
    }

    /**
     * Get the event type identifier
     */
    abstract protected function getEventType(): string;

    /**
     * Get the service name that emitted this event
     */
    abstract protected function getServiceName(): string;

    /**
     * Get the queue name for this event
     */
    public function getQueueName(): string
    {
        return "hrms-{$this->getServiceName()}-events";
    }

    /**
     * Get the SQS queue URL for this event
     */
    public function getSqsQueueUrl(): string
    {
        $queueName = $this->getQueueName();
        $region = config('aws.region', 'us-east-1');
        $accountId = config('aws.account_id');
        
        return "https://sqs.{$region}.amazonaws.com/{$accountId}/{$queueName}";
    }

    /**
     * Convert event to array for serialization
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'tenant_id' => $this->tenantId,
            'event_type' => $this->eventType,
            'timestamp' => $this->timestamp->toISOString(),
            'user_id' => $this->userId,
            'external_tenant_id' => $this->externalTenantId,
            'external_ref_no' => $this->externalRefNo,
            'metadata' => $this->metadata,
            'payload' => $this->payload,
        ];
    }

    /**
     * Convert event to JSON
     */
    public function toJson(): string
    {
        return json_encode($this->toArray());
    }

    /**
     * Check if this event should be published to external services
     */
    public function shouldPublishToExternal(): bool
    {
        return !empty($this->externalTenantId) || !empty($this->externalRefNo);
    }

    /**
     * Get external service endpoints for this event
     */
    public function getExternalEndpoints(): array
    {
        if (!$this->shouldPublishToExternal()) {
            return [];
        }

        return [
            'webhook_url' => config("external_services.{$this->externalTenantId}.webhook_url"),
            'api_endpoint' => config("external_services.{$this->externalTenantId}.api_endpoint"),
        ];
    }
}
