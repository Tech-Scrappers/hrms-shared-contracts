<?php

namespace Shared\Messaging;

use Aws\Sqs\SqsClient;
use Aws\Exception\AwsException;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * SQS Consumer for processing messages from Amazon SQS queues
 * 
 * Provides reliable message consumption with error handling,
 * retry logic, and dead letter queue support.
 */
class SqsConsumer implements MessageConsumer
{
    protected SqsClient $client;
    protected array $config;
    protected string $queueUrl;
    protected int $maxMessages = 10;
    protected int $waitTimeSeconds = 20;
    protected int $visibilityTimeout = 30;

    public function __construct(
        string $queueName,
        ?string $region = null,
        ?string $key = null,
        ?string $secret = null,
        ?string $accountId = null
    ) {
        $this->config = [
            'region' => $region ?? config('aws.region', 'us-east-1'),
            'key' => $key ?? config('aws.key'),
            'secret' => $secret ?? config('aws.secret'),
            'account_id' => $accountId ?? config('aws.account_id'),
        ];

        $this->queueUrl = $this->buildQueueUrl($queueName);
        $this->client = $this->createSqsClient();
    }

    /**
     * Consume messages from SQS queue
     */
    public function consume(callable $handler): void
    {
        Log::info('Starting SQS consumer', [
            'queue_url' => $this->queueUrl,
            'max_messages' => $this->maxMessages,
            'wait_time_seconds' => $this->waitTimeSeconds,
        ]);

        while (true) {
            try {
                $messages = $this->receiveMessages();
                
                if (empty($messages)) {
                    // No messages available, continue polling
                    continue;
                }

                foreach ($messages as $message) {
                    try {
                        $this->processMessage($message, $handler);
                        $this->deleteMessage($message);
                    } catch (Exception $e) {
                        Log::error('Failed to process SQS message', [
                            'message_id' => $message['MessageId'] ?? 'unknown',
                            'receipt_handle' => $message['ReceiptHandle'] ?? 'unknown',
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);

                        // Don't delete the message so it can be retried
                        // The visibility timeout will make it available again
                    }
                }

            } catch (AwsException $e) {
                Log::error('AWS SQS error during message consumption', [
                    'error_code' => $e->getAwsErrorCode(),
                    'error_message' => $e->getAwsErrorMessage(),
                    'request_id' => $e->getAwsRequestId(),
                ]);

                // Wait before retrying to avoid overwhelming AWS
                sleep(5);
            } catch (Exception $e) {
                Log::error('Unexpected error during SQS message consumption', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                sleep(5);
            }
        }
    }

    /**
     * Receive messages from SQS queue
     */
    protected function receiveMessages(): array
    {
        $result = $this->client->receiveMessage([
            'QueueUrl' => $this->queueUrl,
            'MaxNumberOfMessages' => $this->maxMessages,
            'WaitTimeSeconds' => $this->waitTimeSeconds,
            'VisibilityTimeout' => $this->visibilityTimeout,
            'MessageAttributeNames' => ['All'],
        ]);

        return $result->get('Messages') ?? [];
    }

    /**
     * Process a single message
     */
    protected function processMessage(array $message, callable $handler): void
    {
        $messageId = $message['MessageId'] ?? 'unknown';
        $body = $message['Body'] ?? '';
        $attributes = $message['MessageAttributes'] ?? [];

        Log::info('Processing SQS message', [
            'message_id' => $messageId,
            'event_type' => $attributes['EventType']['StringValue'] ?? 'unknown',
            'tenant_id' => $attributes['TenantId']['StringValue'] ?? 'unknown',
            'service' => $attributes['Service']['StringValue'] ?? 'unknown',
        ]);

        try {
            // Parse the message body
            $eventData = json_decode($body, true);
            
            if (!$eventData) {
                throw new Exception('Invalid JSON in message body');
            }

            // Call the handler with the event data
            $handler($eventData);

            Log::info('Successfully processed SQS message', [
                'message_id' => $messageId,
            ]);

        } catch (Exception $e) {
            Log::error('Error processing SQS message', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
                'body' => $body,
            ]);

            throw $e; // Re-throw to prevent message deletion
        }
    }

    /**
     * Delete a processed message from the queue
     */
    protected function deleteMessage(array $message): void
    {
        try {
            $this->client->deleteMessage([
                'QueueUrl' => $this->queueUrl,
                'ReceiptHandle' => $message['ReceiptHandle'],
            ]);

            Log::debug('Deleted processed SQS message', [
                'message_id' => $message['MessageId'] ?? 'unknown',
            ]);

        } catch (AwsException $e) {
            Log::error('Failed to delete SQS message', [
                'message_id' => $message['MessageId'] ?? 'unknown',
                'error' => $e->getAwsErrorMessage(),
            ]);
        }
    }

    /**
     * Create SQS client
     */
    protected function createSqsClient(): SqsClient
    {
        if (!class_exists(SqsClient::class)) {
            throw new Exception('AWS SQS SDK not available. Please install aws/aws-sdk-php');
        }

        if (!$this->config['region'] || !$this->config['key'] || !$this->config['secret']) {
            throw new Exception('AWS credentials not configured. Please set AWS_REGION, AWS_KEY, and AWS_SECRET');
        }

        return new SqsClient([
            'version' => 'latest',
            'region' => $this->config['region'],
            'credentials' => [
                'key' => $this->config['key'],
                'secret' => $this->config['secret'],
            ],
        ]);
    }

    /**
     * Build SQS queue URL
     */
    protected function buildQueueUrl(string $queueName): string
    {
        $accountId = $this->config['account_id'];
        $region = $this->config['region'];

        if (!$accountId) {
            throw new Exception('AWS account ID not configured. Please set AWS_ACCOUNT_ID');
        }

        return "https://sqs.{$region}.amazonaws.com/{$accountId}/{$queueName}";
    }

    /**
     * Set maximum number of messages to receive per batch
     */
    public function setMaxMessages(int $maxMessages): self
    {
        $this->maxMessages = min($maxMessages, 10); // AWS SQS limit
        return $this;
    }

    /**
     * Set wait time for long polling
     */
    public function setWaitTimeSeconds(int $waitTimeSeconds): self
    {
        $this->waitTimeSeconds = min($waitTimeSeconds, 20); // AWS SQS limit
        return $this;
    }

    /**
     * Set visibility timeout for messages
     */
    public function setVisibilityTimeout(int $visibilityTimeout): self
    {
        $this->visibilityTimeout = $visibilityTimeout;
        return $this;
    }

    /**
     * Get queue URL
     */
    public function getQueueUrl(): string
    {
        return $this->queueUrl;
    }

    /**
     * Get queue attributes
     */
    public function getQueueAttributes(): array
    {
        try {
            $result = $this->client->getQueueAttributes([
                'QueueUrl' => $this->queueUrl,
                'AttributeNames' => ['All'],
            ]);

            return $result->get('Attributes') ?? [];
        } catch (AwsException $e) {
            Log::error('Failed to get queue attributes', [
                'queue_url' => $this->queueUrl,
                'error' => $e->getAwsErrorMessage(),
            ]);

            return [];
        }
    }
}
