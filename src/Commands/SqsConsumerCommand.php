<?php

namespace Shared\Commands;

use Illuminate\Console\Command;
use Shared\Messaging\SqsConsumer;
use Shared\Events\EventSubscriber;
use Illuminate\Support\Facades\Log;

/**
 * SQS Consumer Command
 * 
 * Consumes messages from SQS queues and processes them using
 * the EventSubscriber system.
 */
class SqsConsumerCommand extends Command
{
    protected $signature = 'events:consume-sqs 
                            {queue : The SQS queue name to consume from}
                            {--max-messages=10 : Maximum number of messages to process per batch}
                            {--wait-time=20 : Wait time for long polling (seconds)}
                            {--visibility-timeout=30 : Message visibility timeout (seconds)}
                            {--timeout=0 : Maximum time to run the consumer (0 = infinite)}';

    protected $description = 'Consume events from SQS queue';

    protected EventSubscriber $eventSubscriber;
    protected bool $shouldStop = false;

    public function __construct(EventSubscriber $eventSubscriber)
    {
        parent::__construct();
        $this->eventSubscriber = $eventSubscriber;
    }

    public function handle(): int
    {
        $queueName = $this->argument('queue');
        $maxMessages = (int) $this->option('max-messages');
        $waitTime = (int) $this->option('wait-time');
        $visibilityTimeout = (int) $this->option('visibility-timeout');
        $timeout = (int) $this->option('timeout');

        $this->info("Starting SQS consumer for queue: {$queueName}");
        $this->info("Max messages per batch: {$maxMessages}");
        $this->info("Wait time: {$waitTime}s");
        $this->info("Visibility timeout: {$visibilityTimeout}s");

        if ($timeout > 0) {
            $this->info("Timeout: {$timeout}s");
        } else {
            $this->info("Running indefinitely (Ctrl+C to stop)");
        }

        // Set up signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
        }

        $startTime = time();
        $processedCount = 0;

        try {
            $consumer = new SqsConsumer($queueName);
            $consumer->setMaxMessages($maxMessages)
                     ->setWaitTimeSeconds($waitTime)
                     ->setVisibilityTimeout($visibilityTimeout);

            $this->info("Queue URL: {$consumer->getQueueUrl()}");
            $this->newLine();

            $consumer->consume(function ($eventData) use (&$processedCount) {
                try {
                    $this->processEvent($eventData);
                    $processedCount++;
                    
                    if ($processedCount % 10 === 0) {
                        $this->info("Processed {$processedCount} events...");
                    }

                } catch (\Exception $e) {
                    $this->error("Failed to process event: " . $e->getMessage());
                    Log::error('SQS event processing failed', [
                        'event_data' => $eventData,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }

                // Check if we should stop
                if ($this->shouldStop) {
                    return false; // Stop consuming
                }

                // Check timeout
                if ($timeout > 0 && (time() - $startTime) >= $timeout) {
                    $this->info("Timeout reached, stopping consumer...");
                    return false; // Stop consuming
                }
            });

        } catch (\Exception $e) {
            $this->error("Consumer failed: " . $e->getMessage());
            Log::error('SQS consumer failed', [
                'queue' => $queueName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return 1;
        }

        $this->info("Consumer stopped. Total events processed: {$processedCount}");
        return 0;
    }

    /**
     * Process a single event
     */
    protected function processEvent(array $eventData): void
    {
        $eventType = $eventData['event_type'] ?? 'unknown';
        $tenantId = $eventData['tenant_id'] ?? 'unknown';
        $service = $eventData['meta']['service'] ?? 'unknown';

        $this->line("Processing event: {$eventType} (tenant: {$tenantId}, service: {$service})");

        // Use the EventSubscriber to process the event
        $this->eventSubscriber->handle($eventData);
    }

    /**
     * Handle shutdown signals
     */
    public function handleSignal(int $signal): void
    {
        $this->info("\nReceived signal {$signal}, stopping consumer gracefully...");
        $this->shouldStop = true;
    }
}
