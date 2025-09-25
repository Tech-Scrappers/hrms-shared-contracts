<?php

namespace Shared\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DatabaseOptimizationService
{
    /**
     * Cache tenant information to reduce database queries
     */
    public function getCachedTenant(string $tenantId): ?object
    {
        $cacheKey = "tenant:{$tenantId}";
        
        return Cache::remember($cacheKey, 3600, function () use ($tenantId) {
            return DB::connection('pgsql')
                ->table('tenants')
                ->where('id', $tenantId)
                ->where('is_active', true)
                ->select(['id', 'name', 'domain', 'is_active', 'created_at'])
                ->first();
        });
    }

    /**
     * Cache API key validation to reduce database queries
     */
    public function getCachedApiKey(string $keyHash): ?object
    {
        $cacheKey = "api_key:{$keyHash}";
        
        return Cache::remember($cacheKey, 300, function () use ($keyHash) {
            return DB::connection('pgsql')
                ->table('api_keys')
                ->where('key_hash', $keyHash)
                ->where('is_active', true)
                ->select(['id', 'tenant_id', 'name', 'permissions', 'expires_at', 'is_active'])
                ->first();
        });
    }

    /**
     * Invalidate tenant cache
     */
    public function invalidateTenantCache(string $tenantId): void
    {
        $cacheKey = "tenant:{$tenantId}";
        Cache::forget($cacheKey);
    }

    /**
     * Invalidate API key cache
     */
    public function invalidateApiKeyCache(string $keyHash): void
    {
        $cacheKey = "api_key:{$keyHash}";
        Cache::forget($cacheKey);
    }

    /**
     * Batch load multiple tenants
     */
    public function batchLoadTenants(array $tenantIds): array
    {
        $cacheKeys = array_map(fn($id) => "tenant:{$id}", $tenantIds);
        $cached = Cache::many($cacheKeys);
        
        $missing = [];
        foreach ($tenantIds as $tenantId) {
            if (!isset($cached["tenant:{$tenantId}"])) {
                $missing[] = $tenantId;
            }
        }

        if (!empty($missing)) {
            $tenants = DB::connection('pgsql')
                ->table('tenants')
                ->whereIn('id', $missing)
                ->where('is_active', true)
                ->select(['id', 'name', 'domain', 'is_active', 'created_at'])
                ->get()
                ->keyBy('id');

            foreach ($tenants as $tenant) {
                Cache::put("tenant:{$tenant->id}", $tenant, 3600);
            }

            $cached = array_merge($cached, $tenants->toArray());
        }

        return $cached;
    }

    /**
     * Optimize database connection pool
     */
    public function optimizeConnectionPool(): void
    {
        try {
            // Set connection pool settings for PostgreSQL
            DB::connection('pgsql')->statement('SET max_connections = 100');
            DB::connection('pgsql')->statement('SET shared_buffers = 256MB');
            DB::connection('pgsql')->statement('SET effective_cache_size = 1GB');
            DB::connection('pgsql')->statement('SET work_mem = 4MB');
            DB::connection('pgsql')->statement('SET maintenance_work_mem = 64MB');
            
            Log::info('Database connection pool optimized');
        } catch (\Exception $e) {
            Log::warning('Failed to optimize database connection pool', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get database performance metrics
     */
    public function getPerformanceMetrics(): array
    {
        try {
            $metrics = DB::connection('pgsql')->select("
                SELECT 
                    schemaname,
                    tablename,
                    attname,
                    n_distinct,
                    correlation
                FROM pg_stats 
                WHERE schemaname = 'public' 
                ORDER BY n_distinct DESC 
                LIMIT 10
            ");

            $connections = DB::connection('pgsql')->select("
                SELECT 
                    count(*) as active_connections,
                    state
                FROM pg_stat_activity 
                WHERE state = 'active'
                GROUP BY state
            ");

            return [
                'table_stats' => $metrics,
                'connections' => $connections,
                'timestamp' => now()->toISOString(),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get database performance metrics', [
                'error' => $e->getMessage(),
            ]);

            return [
                'error' => 'Failed to retrieve metrics',
                'timestamp' => now()->toISOString(),
            ];
        }
    }

    /**
     * Clean up old security events
     */
    public function cleanupOldSecurityEvents(int $days = 90): int
    {
        try {
            $cutoffDate = now()->subDays($days);
            
            $deleted = DB::connection('pgsql')
                ->table('security_events')
                ->where('created_at', '<', $cutoffDate)
                ->delete();

            Log::info('Cleaned up old security events', [
                'deleted_count' => $deleted,
                'cutoff_date' => $cutoffDate->toISOString(),
            ]);

            return $deleted;
        } catch (\Exception $e) {
            Log::error('Failed to cleanup old security events', [
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Analyze query performance
     */
    public function analyzeQueryPerformance(): array
    {
        try {
            $slowQueries = DB::connection('pgsql')->select("
                SELECT 
                    query,
                    calls,
                    total_time,
                    mean_time,
                    rows
                FROM pg_stat_statements 
                WHERE mean_time > 1000 
                ORDER BY mean_time DESC 
                LIMIT 10
            ");

            return [
                'slow_queries' => $slowQueries,
                'timestamp' => now()->toISOString(),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to analyze query performance', [
                'error' => $e->getMessage(),
            ]);

            return [
                'error' => 'Failed to analyze performance',
                'timestamp' => now()->toISOString(),
            ];
        }
    }
}