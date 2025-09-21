<?php

namespace Shared\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Shared\Events\EventBus;
use Shared\Events\TenantMigrationEvent;

class RetryFailedEventsCommand extends Command
{
    protected $signature = 'events:retry-failed {serviceName} {--max-retries=3}';

    protected $description = 'Retry failed events for a specific service.';

    public function handle()
    {
        $serviceName = $this->argument('serviceName');
        $maxRetries = (int) $this->option('max-retries');

        $this->info("Retrying failed events for {$serviceName}...");

        // Process failed publish events
        $this->retryFailedPublishEvents($serviceName, $maxRetries);

        // Process failed migration events
        $this->retryFailedMigrationEvents($serviceName, $maxRetries);

        $this->info("Failed events retry completed for {$serviceName}.");
    }

    private function retryFailedPublishEvents(string $serviceName, int $maxRetries): void
    {
        $failedEvents = Redis::lrange('failed_publish_events', 0, -1);
        $retryCount = 0;

        foreach ($failedEvents as $eventJson) {
            $event = json_decode($eventJson, true);

            if (! $event || ! isset($event['metadata']['service'])) {
                continue;
            }

            // Only retry events for this service
            if ($event['metadata']['service'] !== $serviceName) {
                continue;
            }

            try {
                // Recreate the event
                $migrationEvent = new TenantMigrationEvent(
                    $event['payload']['tenant'] ?? [],
                    $event['payload']['service'] ?? 'unknown',
                    $event['metadata']['tenant_id'] ?? null
                );

                // Publish the event
                $eventBus = new EventBus($serviceName);
                $eventBus->publish($migrationEvent);

                // Remove from failed events
                Redis::lrem('failed_publish_events', 1, $eventJson);

                $retryCount++;
                $this->info("Retried failed publish event: {$event['metadata']['event_id']}");

            } catch (\Exception $e) {
                Log::error('Failed to retry publish event', [
                    'service' => $serviceName,
                    'event_id' => $event['metadata']['event_id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($retryCount > 0) {
            $this->info("Retried {$retryCount} failed publish events for {$serviceName}.");
        }
    }

    private function retryFailedMigrationEvents(string $serviceName, int $maxRetries): void
    {
        $retryEvents = Redis::lrange('migration_retry_queue', 0, -1);
        $retryCount = 0;

        foreach ($retryEvents as $eventJson) {
            $event = json_decode($eventJson, true);

            if (! $event || ! isset($event['tenant']['id'])) {
                continue;
            }

            $currentRetryCount = $event['retry_count'] ?? 0;

            if ($currentRetryCount >= $maxRetries) {
                // Move to permanently failed events
                Redis::lrem('migration_retry_queue', 1, $eventJson);
                Redis::lpush('permanently_failed_events', $eventJson);
                continue;
            }

            try {
                $tenant = $event['tenant'];
                $failedServices = $event['failed_services'] ?? [];

                // Retry migration events for failed services
                foreach ($failedServices as $service) {
                    $migrationEvent = new TenantMigrationEvent($tenant, $service, $tenant['id']);
                    $eventBus = new EventBus($serviceName);
                    $eventBus->publish($migrationEvent);
                }

                // Update retry count
                $event['retry_count'] = $currentRetryCount + 1;
                $event['last_retry_at'] = now()->toISOString();

                // Update the event in the queue
                Redis::lrem('migration_retry_queue', 1, $eventJson);
                Redis::lpush('migration_retry_queue', json_encode($event));

                $retryCount++;
                $this->info("Retried migration events for tenant: {$tenant['id']}");

            } catch (\Exception $e) {
                Log::error('Failed to retry migration events', [
                    'service' => $serviceName,
                    'tenant_id' => $event['tenant']['id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($retryCount > 0) {
            $this->info("Retried {$retryCount} migration events for {$serviceName}.");
        }
    }
}
