<?php

namespace Shared\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PerformanceMonitoringMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        
        // Get database query count before request
        $initialQueryCount = $this->getQueryCount();
        
        $response = $next($request);
        
        // Calculate performance metrics
        $executionTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds
        $memoryUsage = memory_get_usage(true) - $startMemory;
        $peakMemory = memory_get_peak_usage(true);
        $queryCount = $this->getQueryCount() - $initialQueryCount;
        
        // Add performance headers
        $this->addPerformanceHeaders($response, $executionTime, $memoryUsage, $peakMemory, $queryCount);
        
        // Log performance metrics
        $this->logPerformanceMetrics($request, $response, $executionTime, $memoryUsage, $peakMemory, $queryCount);
        
        // Check for performance issues
        $this->checkPerformanceThresholds($request, $executionTime, $memoryUsage, $queryCount);
        
        return $response;
    }

    /**
     * Add performance headers to response
     */
    private function addPerformanceHeaders(Response $response, float $executionTime, int $memoryUsage, int $peakMemory, int $queryCount): void
    {
        $response->headers->set('X-Execution-Time', round($executionTime, 2) . 'ms');
        $response->headers->set('X-Memory-Usage', $this->formatBytes($memoryUsage));
        $response->headers->set('X-Peak-Memory', $this->formatBytes($peakMemory));
        $response->headers->set('X-Database-Queries', $queryCount);
        $response->headers->set('X-Performance-Score', $this->calculatePerformanceScore($executionTime, $memoryUsage, $queryCount));
    }

    /**
     * Log performance metrics
     */
    private function logPerformanceMetrics(Request $request, Response $response, float $executionTime, int $memoryUsage, int $peakMemory, int $queryCount): void
    {
        $logData = [
            'timestamp' => now()->toISOString(),
            'level' => 'INFO',
            'service' => config('app.name', 'hrms-service'),
            'request_id' => $request->header('X-Request-ID', (string) \Illuminate\Support\Str::uuid()),
            'tenant_id' => $request->header('HRMS-Client-ID'),
            'endpoint' => $request->path(),
            'method' => $request->method(),
            'status_code' => $response->getStatusCode(),
            'performance' => [
                'execution_time_ms' => round($executionTime, 2),
                'memory_usage_bytes' => $memoryUsage,
                'memory_usage_mb' => round($memoryUsage / 1024 / 1024, 2),
                'peak_memory_bytes' => $peakMemory,
                'peak_memory_mb' => round($peakMemory / 1024 / 1024, 2),
                'database_queries' => $queryCount,
                'performance_score' => $this->calculatePerformanceScore($executionTime, $memoryUsage, $queryCount)
            ],
            'message' => 'Performance metrics recorded'
        ];

        // Log based on performance level
        if ($executionTime > 1000 || $memoryUsage > 50 * 1024 * 1024 || $queryCount > 50) {
            Log::warning('High resource usage detected', $logData);
        } else {
            Log::info('Performance metrics', $logData);
        }
    }

    /**
     * Check performance thresholds and alert if necessary
     */
    private function checkPerformanceThresholds(Request $request, float $executionTime, int $memoryUsage, int $queryCount): void
    {
        $thresholds = config('performance.thresholds', [
            'execution_time_ms' => 1000,
            'memory_usage_mb' => 50,
            'database_queries' => 50
        ]);

        $alerts = [];

        if ($executionTime > $thresholds['execution_time_ms']) {
            $alerts[] = "Slow response time: {$executionTime}ms";
        }

        if (($memoryUsage / 1024 / 1024) > $thresholds['memory_usage_mb']) {
            $alerts[] = "High memory usage: " . round($memoryUsage / 1024 / 1024, 2) . "MB";
        }

        if ($queryCount > $thresholds['database_queries']) {
            $alerts[] = "High query count: {$queryCount} queries";
        }

        if (!empty($alerts)) {
            Log::error('Performance threshold exceeded', [
                'endpoint' => $request->path(),
                'method' => $request->method(),
                'tenant_id' => $request->header('HRMS-Client-ID'),
                'alerts' => $alerts,
                'execution_time_ms' => $executionTime,
                'memory_usage_mb' => round($memoryUsage / 1024 / 1024, 2),
                'database_queries' => $queryCount
            ]);
        }
    }

    /**
     * Get current database query count
     */
    private function getQueryCount(): int
    {
        try {
            return \DB::getQueryLog() ? count(\DB::getQueryLog()) : 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Calculate performance score (0-100)
     */
    private function calculatePerformanceScore(float $executionTime, int $memoryUsage, int $queryCount): int
    {
        $score = 100;

        // Deduct points for slow execution time
        if ($executionTime > 100) $score -= min(30, ($executionTime - 100) / 10);
        if ($executionTime > 500) $score -= 20;
        if ($executionTime > 1000) $score -= 30;

        // Deduct points for high memory usage
        $memoryMB = $memoryUsage / 1024 / 1024;
        if ($memoryMB > 10) $score -= min(20, ($memoryMB - 10) / 2);
        if ($memoryMB > 50) $score -= 20;

        // Deduct points for high query count
        if ($queryCount > 10) $score -= min(20, ($queryCount - 10) / 2);
        if ($queryCount > 50) $score -= 20;

        return max(0, (int) $score);
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;
        
        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }
        
        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }
}
