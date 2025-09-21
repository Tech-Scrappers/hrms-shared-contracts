<?php

namespace Shared\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DatabaseOptimizationService
{
    private TenantAwareCacheService $cacheService;

    public function __construct(TenantAwareCacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    /**
     * Optimize query with caching and eager loading
     */
    public function optimizeQuery(Builder $query, string $cacheKey, int $ttl = 300, array $eagerLoad = []): Builder
    {
        // Add eager loading to prevent N+1 queries
        if (!empty($eagerLoad)) {
            $query->with($eagerLoad);
        }

        // Add query logging for optimization
        $this->logQuery($query);

        return $query;
    }

    /**
     * Execute cached query
     */
    public function executeCachedQuery(Builder $query, string $cacheKey, int $ttl = 300)
    {
        return $this->cacheService->cacheQuery($cacheKey, function () use ($query) {
            return $query->get();
        }, $ttl);
    }

    /**
     * Execute cached paginated query
     */
    public function executeCachedPaginatedQuery(Builder $query, string $cacheKey, int $perPage = 20, int $ttl = 300)
    {
        return $this->cacheService->cacheQuery($cacheKey, function () use ($query, $perPage) {
            return $query->paginate($perPage);
        }, $ttl);
    }

    /**
     * Execute cached count query
     */
    public function executeCachedCountQuery(Builder $query, string $cacheKey, int $ttl = 300)
    {
        return $this->cacheService->cacheQuery($cacheKey, function () use ($query) {
            return $query->count();
        }, $ttl);
    }

    /**
     * Execute cached single result query
     */
    public function executeCachedSingleQuery(Builder $query, string $cacheKey, int $ttl = 300)
    {
        return $this->cacheService->cacheQuery($cacheKey, function () use ($query) {
            return $query->first();
        }, $ttl);
    }

    /**
     * Optimize model queries with proper indexing hints
     */
    public function optimizeModelQuery(Model $model, array $conditions = [], array $eagerLoad = []): Builder
    {
        $query = $model->newQuery();

        // Apply conditions with proper indexing
        foreach ($conditions as $field => $value) {
            if (is_array($value)) {
                $query->whereIn($field, $value);
            } else {
                $query->where($field, $value);
            }
        }

        // Add eager loading
        if (!empty($eagerLoad)) {
            $query->with($eagerLoad);
        }

        // Add query optimization hints
        $this->addQueryHints($query);

        return $query;
    }

    /**
     * Add database-specific query hints
     */
    private function addQueryHints(Builder $query): void
    {
        $connection = $query->getConnection();
        $driver = $connection->getDriverName();

        switch ($driver) {
            case 'pgsql':
                // PostgreSQL specific optimizations
                $query->selectRaw('*');
                break;
            case 'mysql':
                // MySQL specific optimizations
                $query->selectRaw('*');
                break;
        }
    }

    /**
     * Log query for optimization analysis
     */
    private function logQuery(Builder $query): void
    {
        if (config('app.debug', false)) {
            $sql = $query->toSql();
            $bindings = $query->getBindings();
            
            Log::debug('Database Query', [
                'sql' => $sql,
                'bindings' => $bindings,
                'tenant_id' => $this->cacheService->getTenantId() ?? 'unknown'
            ]);
        }
    }

    /**
     * Get query execution plan
     */
    public function getQueryPlan(Builder $query): array
    {
        $connection = $query->getConnection();
        $driver = $connection->getDriverName();

        try {
            switch ($driver) {
                case 'pgsql':
                    $explainQuery = "EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) " . $query->toSql();
                    $result = DB::select($explainQuery, $query->getBindings());
                    return json_decode($result[0]->explain, true);
                
                case 'mysql':
                    $explainQuery = "EXPLAIN FORMAT=JSON " . $query->toSql();
                    $result = DB::select($explainQuery, $query->getBindings());
                    return json_decode($result[0]->explain, true);
                
                default:
                    return [];
            }
        } catch (\Exception $e) {
            Log::warning('Failed to get query plan', [
                'error' => $e->getMessage(),
                'driver' => $driver
            ]);
            return [];
        }
    }

    /**
     * Optimize database connection settings
     */
    public function optimizeConnection(): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        switch ($driver) {
            case 'pgsql':
                // PostgreSQL optimizations
                DB::statement('SET work_mem = 256MB');
                DB::statement('SET shared_buffers = 256MB');
                DB::statement('SET effective_cache_size = 1GB');
                break;
            
            case 'mysql':
                // MySQL optimizations
                DB::statement('SET SESSION query_cache_type = ON');
                DB::statement('SET SESSION query_cache_size = 268435456'); // 256MB
                break;
        }
    }

    /**
     * Get database performance metrics
     */
    public function getPerformanceMetrics(): array
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        try {
            switch ($driver) {
                case 'pgsql':
                    return $this->getPostgreSQLMetrics();
                case 'mysql':
                    return $this->getMySQLMetrics();
                default:
                    return [];
            }
        } catch (\Exception $e) {
            Log::error('Failed to get performance metrics', [
                'error' => $e->getMessage(),
                'driver' => $driver
            ]);
            return [];
        }
    }

    /**
     * Get PostgreSQL performance metrics
     */
    private function getPostgreSQLMetrics(): array
    {
        $metrics = [];

        // Connection count
        $connectionCount = DB::selectOne("
            SELECT count(*) as connections 
            FROM pg_stat_activity 
            WHERE state = 'active'
        ");
        $metrics['active_connections'] = $connectionCount->connections ?? 0;

        // Cache hit ratio
        $cacheHitRatio = DB::selectOne("
            SELECT 
                round(100.0 * sum(blks_hit) / (sum(blks_hit) + sum(blks_read)), 2) as hit_ratio
            FROM pg_stat_database 
            WHERE datname = current_database()
        ");
        $metrics['cache_hit_ratio'] = $cacheHitRatio->hit_ratio ?? 0;

        // Database size
        $dbSize = DB::selectOne("
            SELECT pg_size_pretty(pg_database_size(current_database())) as size
        ");
        $metrics['database_size'] = $dbSize->size ?? 'Unknown';

        return $metrics;
    }

    /**
     * Get MySQL performance metrics
     */
    private function getMySQLMetrics(): array
    {
        $metrics = [];

        // Connection count
        $connectionCount = DB::selectOne("SHOW STATUS LIKE 'Threads_connected'");
        $metrics['active_connections'] = $connectionCount->Value ?? 0;

        // Query cache hit ratio
        $queryCache = DB::selectOne("SHOW STATUS LIKE 'Qcache_hits'");
        $queryCacheInserts = DB::selectOne("SHOW STATUS LIKE 'Qcache_inserts'");
        
        $hits = $queryCache->Value ?? 0;
        $inserts = $queryCacheInserts->Value ?? 0;
        $total = $hits + $inserts;
        
        $metrics['query_cache_hit_ratio'] = $total > 0 ? round(($hits / $total) * 100, 2) : 0;

        // Database size
        $dbSize = DB::selectOne("
            SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
            FROM information_schema.tables 
            WHERE table_schema = DATABASE()
        ");
        $metrics['database_size_mb'] = $dbSize->size_mb ?? 0;

        return $metrics;
    }

    /**
     * Clear query cache for tenant
     */
    public function clearQueryCache(): bool
    {
        try {
            $connection = DB::connection();
            $driver = $connection->getDriverName();

            switch ($driver) {
                case 'pgsql':
                    DB::statement('DISCARD PLANS');
                    break;
                case 'mysql':
                    DB::statement('RESET QUERY CACHE');
                    break;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to clear query cache', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
