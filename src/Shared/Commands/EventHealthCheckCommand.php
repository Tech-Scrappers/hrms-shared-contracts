<?php

namespace Shared\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class EventHealthCheckCommand extends Command
{
    protected $signature = 'events:health-check {serviceName}';

    protected $description = 'Check the health of the event system for a specific service.';

    public function handle()
    {
        $serviceName = $this->argument('serviceName');

        $this->info("Checking event system health for {$serviceName}...");

        $healthStatus = $this->checkEventSystemHealth($serviceName);

        $this->displayHealthStatus($healthStatus);

        return $healthStatus['overall_health'] ? 0 : 1;
    }

    private function checkEventSystemHealth(string $serviceName): array
    {
        $health = [
            'service' => $serviceName,
            'timestamp' => now()->toISOString(),
            'overall_health' => true,
            'checks' => [],
        ];

        // Check Redis connection
        $redisHealth = $this->checkRedisConnection();
        $health['checks']['redis'] = $redisHealth;

        if (! $redisHealth['healthy']) {
            $health['overall_health'] = false;
        }

        // Check event queue status
        $queueHealth = $this->checkEventQueueStatus();
        $health['checks']['event_queue'] = $queueHealth;

        if (! $queueHealth['healthy']) {
            $health['overall_health'] = false;
        }

        // Check failed events
        $failedEventsHealth = $this->checkFailedEvents();
        $health['checks']['failed_events'] = $failedEventsHealth;

        if (! $failedEventsHealth['healthy']) {
            $health['overall_health'] = false;
        }

        // Check migration retry queue
        $migrationRetryHealth = $this->checkMigrationRetryQueue();
        $health['checks']['migration_retry'] = $migrationRetryHealth;

        if (! $migrationRetryHealth['healthy']) {
            $health['overall_health'] = false;
        }

        return $health;
    }

    private function checkRedisConnection(): array
    {
        try {
            $ping = Redis::ping();

            return [
                'healthy' => $ping === 'PONG',
                'status' => $ping === 'PONG' ? 'Connected' : 'Disconnected',
                'response' => $ping,
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'status' => 'Error',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function checkEventQueueStatus(): array
    {
        try {
            $queueLength = Redis::llen('hrms_events:queue');
            $failedLength = Redis::llen('failed_events');

            $isHealthy = $queueLength < 1000 && $failedLength < 100;

            return [
                'healthy' => $isHealthy,
                'status' => $isHealthy ? 'Normal' : 'Warning',
                'queue_length' => $queueLength,
                'failed_events' => $failedLength,
                'message' => $isHealthy ? 'Event queue is healthy' : 'Event queue has issues',
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'status' => 'Error',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function checkFailedEvents(): array
    {
        try {
            $failedPublishEvents = Redis::llen('failed_publish_events');
            $permanentlyFailedEvents = Redis::llen('permanently_failed_events');

            $isHealthy = $failedPublishEvents < 50 && $permanentlyFailedEvents < 10;

            return [
                'healthy' => $isHealthy,
                'status' => $isHealthy ? 'Normal' : 'Warning',
                'failed_publish_events' => $failedPublishEvents,
                'permanently_failed_events' => $permanentlyFailedEvents,
                'message' => $isHealthy ? 'Failed events are within acceptable limits' : 'Too many failed events',
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'status' => 'Error',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function checkMigrationRetryQueue(): array
    {
        try {
            $retryQueueLength = Redis::llen('migration_retry_queue');

            $isHealthy = $retryQueueLength < 20;

            return [
                'healthy' => $isHealthy,
                'status' => $isHealthy ? 'Normal' : 'Warning',
                'retry_queue_length' => $retryQueueLength,
                'message' => $isHealthy ? 'Migration retry queue is healthy' : 'Too many migration retries pending',
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'status' => 'Error',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function displayHealthStatus(array $health): void
    {
        $this->info("Event System Health Check for {$health['service']}");
        $this->info('Overall Health: '.($health['overall_health'] ? '✅ Healthy' : '❌ Unhealthy'));
        $this->info("Timestamp: {$health['timestamp']}");
        $this->newLine();

        foreach ($health['checks'] as $checkName => $checkResult) {
            $status = $checkResult['healthy'] ? '✅' : '❌';
            $this->info("{$status} {$checkName}: {$checkResult['status']}");

            if (isset($checkResult['message'])) {
                $this->line("   {$checkResult['message']}");
            }

            if (isset($checkResult['error'])) {
                $this->error("   Error: {$checkResult['error']}");
            }

            // Display specific metrics
            foreach ($checkResult as $key => $value) {
                if (in_array($key, ['healthy', 'status', 'message', 'error'])) {
                    continue;
                }
                $this->line("   {$key}: {$value}");
            }
        }
    }
}
