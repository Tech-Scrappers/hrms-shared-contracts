<?php

namespace Shared\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Shared\Traits\EnterpriseApiResponseTrait;

class HealthController
{
    use EnterpriseApiResponseTrait;

    /**
     * Basic health check
     */
    public function health(): JsonResponse
    {
        return $this->successResponse([
            'status' => 'healthy',
            'service' => config('app.name', 'hrms-service'),
            'version' => config('app.version', '1.0.0'),
            'timestamp' => now()->toISOString(),
        ], 'Service is healthy');
    }

    /**
     * Detailed health check
     */
    public function detailed(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'redis' => $this->checkRedis(),
            'memory' => $this->checkMemory(),
            'disk' => $this->checkDisk(),
        ];

        $overallStatus = $this->determineOverallStatus($checks);

        return $this->successResponse([
            'status' => $overallStatus,
            'service' => config('app.name', 'hrms-service'),
            'version' => config('app.version', '1.0.0'),
            'timestamp' => now()->toISOString(),
            'checks' => $checks,
        ], 'Detailed health check completed');
    }

    /**
     * Readiness check
     */
    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
        ];

        $isReady = $this->isServiceReady($checks);

        if (! $isReady) {
            return $this->errorResponse(
                'Service is not ready',
                503,
                'SERVICE_NOT_READY',
                'server_error',
                ['checks' => $checks]
            );
        }

        return $this->successResponse([
            'status' => 'ready',
            'service' => config('app.name', 'hrms-service'),
            'timestamp' => now()->toISOString(),
        ], 'Service is ready to accept traffic');
    }

    /**
     * Liveness check
     */
    public function live(): JsonResponse
    {
        return $this->successResponse([
            'status' => 'alive',
            'service' => config('app.name', 'hrms-service'),
            'timestamp' => now()->toISOString(),
        ], 'Service is alive');
    }

    /**
     * Check database connectivity
     */
    private function checkDatabase(): array
    {
        try {
            $startTime = microtime(true);
            DB::connection()->getPdo();
            $responseTime = (microtime(true) - $startTime) * 1000;

            return [
                'status' => 'healthy',
                'response_time_ms' => (int) $responseTime,
                'connection' => 'active',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'connection' => 'failed',
            ];
        }
    }

    /**
     * Check cache connectivity
     */
    private function checkCache(): array
    {
        try {
            $startTime = microtime(true);
            $testKey = 'health_check_'.time();
            Cache::put($testKey, 'test', 10);
            $value = Cache::get($testKey);
            Cache::forget($testKey);
            $responseTime = (microtime(true) - $startTime) * 1000;

            return [
                'status' => $value === 'test' ? 'healthy' : 'unhealthy',
                'response_time_ms' => (int) $responseTime,
                'connection' => 'active',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'connection' => 'failed',
            ];
        }
    }

    /**
     * Check Redis connectivity
     */
    private function checkRedis(): array
    {
        try {
            $startTime = microtime(true);
            Redis::ping();
            $responseTime = (microtime(true) - $startTime) * 1000;

            return [
                'status' => 'healthy',
                'response_time_ms' => (int) $responseTime,
                'connection' => 'active',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'connection' => 'failed',
            ];
        }
    }

    /**
     * Check memory usage
     */
    private function checkMemory(): array
    {
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
        $memoryUsagePercent = ($memoryUsage / $memoryLimit) * 100;

        return [
            'status' => $memoryUsagePercent < 80 ? 'healthy' : 'warning',
            'usage_mb' => round($memoryUsage / 1024 / 1024, 2),
            'limit_mb' => round($memoryLimit / 1024 / 1024, 2),
            'usage_percent' => round($memoryUsagePercent, 2),
        ];
    }

    /**
     * Check disk usage
     */
    private function checkDisk(): array
    {
        $diskFree = disk_free_space('/');
        $diskTotal = disk_total_space('/');
        $diskUsagePercent = (($diskTotal - $diskFree) / $diskTotal) * 100;

        return [
            'status' => $diskUsagePercent < 90 ? 'healthy' : 'warning',
            'free_gb' => round($diskFree / 1024 / 1024 / 1024, 2),
            'total_gb' => round($diskTotal / 1024 / 1024 / 1024, 2),
            'usage_percent' => round($diskUsagePercent, 2),
        ];
    }

    /**
     * Determine overall status from checks
     */
    private function determineOverallStatus(array $checks): string
    {
        $statuses = array_column($checks, 'status');

        if (in_array('unhealthy', $statuses)) {
            return 'unhealthy';
        }

        if (in_array('warning', $statuses)) {
            return 'degraded';
        }

        return 'healthy';
    }

    /**
     * Check if service is ready
     */
    private function isServiceReady(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['status'] !== 'healthy') {
                return false;
            }
        }

        return true;
    }

    /**
     * Parse memory limit string to bytes
     */
    private function parseMemoryLimit(string $memoryLimit): int
    {
        $memoryLimit = trim($memoryLimit);
        $last = strtolower($memoryLimit[strlen($memoryLimit) - 1]);
        $memoryLimit = (int) $memoryLimit;

        switch ($last) {
            case 'g':
                $memoryLimit *= 1024;
            case 'm':
                $memoryLimit *= 1024;
            case 'k':
                $memoryLimit *= 1024;
        }

        return $memoryLimit;
    }
}
