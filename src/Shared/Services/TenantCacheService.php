<?php

namespace Shared\Services;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TenantCacheService
{
    private const CACHE_TTL = 3600; // 1 hour default

    private const SHORT_TTL = 300;  // 5 minutes

    private const LONG_TTL = 86400; // 24 hours

    /**
     * Get tenant-aware cache key
     */
    private function getCacheKey(string $tenantId, string $key): string
    {
        return "tenant:{$tenantId}:{$key}";
    }

    /**
     * Remember cache with tenant isolation
     *
     * @return mixed
     */
    public function remember(string $tenantId, string $key, callable $callback, int $ttl = self::CACHE_TTL)
    {
        $cacheKey = $this->getCacheKey($tenantId, $key);

        try {
            return Cache::remember($cacheKey, $ttl, $callback);
        } catch (Exception $e) {
            Log::error('Tenant cache remember failed', [
                'tenant_id' => $tenantId,
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            // Fallback to callback execution
            return $callback();
        }
    }

    /**
     * Get cache with tenant isolation
     *
     * @param  mixed  $default
     * @return mixed
     */
    public function get(string $tenantId, string $key, $default = null)
    {
        $cacheKey = $this->getCacheKey($tenantId, $key);

        try {
            return Cache::get($cacheKey, $default);
        } catch (Exception $e) {
            Log::error('Tenant cache get failed', [
                'tenant_id' => $tenantId,
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return $default;
        }
    }

    /**
     * Set cache with tenant isolation
     *
     * @param  mixed  $value
     */
    public function set(string $tenantId, string $key, $value, int $ttl = self::CACHE_TTL): bool
    {
        $cacheKey = $this->getCacheKey($tenantId, $key);

        try {
            return Cache::put($cacheKey, $value, $ttl);
        } catch (Exception $e) {
            Log::error('Tenant cache set failed', [
                'tenant_id' => $tenantId,
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Delete cache with tenant isolation
     */
    public function forget(string $tenantId, string $key): bool
    {
        $cacheKey = $this->getCacheKey($tenantId, $key);

        try {
            return Cache::forget($cacheKey);
        } catch (Exception $e) {
            Log::error('Tenant cache forget failed', [
                'tenant_id' => $tenantId,
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Clear all cache for a tenant
     */
    public function clearTenant(string $tenantId): bool
    {
        try {
            $pattern = $this->getCacheKey($tenantId, '*');

            // Get all keys matching the pattern
            $keys = Cache::getRedis()->keys($pattern);

            if (! empty($keys)) {
                return Cache::getRedis()->del($keys) > 0;
            }

            return true;
        } catch (Exception $e) {
            Log::error('Tenant cache clear failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Cache employee data
     *
     * @param  mixed  $data
     */
    public function cacheEmployee(string $tenantId, string $employeeId, $data, int $ttl = self::CACHE_TTL): bool
    {
        return $this->set($tenantId, "employee:{$employeeId}", $data, $ttl);
    }

    /**
     * Get cached employee data
     *
     * @return mixed
     */
    public function getCachedEmployee(string $tenantId, string $employeeId)
    {
        return $this->get($tenantId, "employee:{$employeeId}");
    }

    /**
     * Cache department data
     *
     * @param  mixed  $data
     */
    public function cacheDepartment(string $tenantId, string $departmentId, $data, int $ttl = self::CACHE_TTL): bool
    {
        return $this->set($tenantId, "department:{$departmentId}", $data, $ttl);
    }

    /**
     * Get cached department data
     *
     * @return mixed
     */
    public function getCachedDepartment(string $tenantId, string $departmentId)
    {
        return $this->get($tenantId, "department:{$departmentId}");
    }

    /**
     * Cache attendance data
     *
     * @param  mixed  $data
     */
    public function cacheAttendance(string $tenantId, string $employeeId, string $date, $data, int $ttl = self::SHORT_TTL): bool
    {
        return $this->set($tenantId, "attendance:{$employeeId}:{$date}", $data, $ttl);
    }

    /**
     * Get cached attendance data
     *
     * @return mixed
     */
    public function getCachedAttendance(string $tenantId, string $employeeId, string $date)
    {
        return $this->get($tenantId, "attendance:{$employeeId}:{$date}");
    }

    /**
     * Cache statistics data
     *
     * @param  mixed  $data
     */
    public function cacheStatistics(string $tenantId, string $type, $data, int $ttl = self::SHORT_TTL): bool
    {
        return $this->set($tenantId, "stats:{$type}", $data, $ttl);
    }

    /**
     * Get cached statistics data
     *
     * @return mixed
     */
    public function getCachedStatistics(string $tenantId, string $type)
    {
        return $this->get($tenantId, "stats:{$type}");
    }

    /**
     * Cache API response
     *
     * @param  mixed  $data
     */
    public function cacheApiResponse(string $tenantId, string $endpoint, array $params, $data, int $ttl = self::CACHE_TTL): bool
    {
        $key = "api:{$endpoint}:".md5(serialize($params));

        return $this->set($tenantId, $key, $data, $ttl);
    }

    /**
     * Get cached API response
     *
     * @return mixed
     */
    public function getCachedApiResponse(string $tenantId, string $endpoint, array $params)
    {
        $key = "api:{$endpoint}:".md5(serialize($params));

        return $this->get($tenantId, $key);
    }

    /**
     * Cache with tags for easier invalidation
     *
     * @param  mixed  $value
     */
    public function cacheWithTags(string $tenantId, array $tags, string $key, $value, int $ttl = self::CACHE_TTL): bool
    {
        $cacheKey = $this->getCacheKey($tenantId, $key);

        try {
            return Cache::tags($tags)->put($cacheKey, $value, $ttl);
        } catch (Exception $e) {
            Log::error('Tenant cache with tags failed', [
                'tenant_id' => $tenantId,
                'tags' => $tags,
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Invalidate cache by tags
     */
    public function invalidateByTags(string $tenantId, array $tags): bool
    {
        try {
            return Cache::tags($tags)->flush();
        } catch (Exception $e) {
            Log::error('Tenant cache invalidate by tags failed', [
                'tenant_id' => $tenantId,
                'tags' => $tags,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get cache statistics for tenant
     */
    public function getTenantStats(string $tenantId): array
    {
        try {
            $pattern = $this->getCacheKey($tenantId, '*');
            $keys = Cache::getRedis()->keys($pattern);

            return [
                'tenant_id' => $tenantId,
                'total_keys' => count($keys),
                'memory_usage' => $this->calculateMemoryUsage($keys),
            ];
        } catch (Exception $e) {
            Log::error('Tenant cache stats failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return [
                'tenant_id' => $tenantId,
                'total_keys' => 0,
                'memory_usage' => '0B',
            ];
        }
    }

    /**
     * Calculate memory usage for keys
     */
    private function calculateMemoryUsage(array $keys): string
    {
        if (empty($keys)) {
            return '0B';
        }

        try {
            $totalSize = 0;
            foreach ($keys as $key) {
                $size = Cache::getRedis()->memory('usage', $key);
                $totalSize += $size;
            }

            return $this->formatBytes($totalSize);
        } catch (Exception $e) {
            return 'Unknown';
        }
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, 2).' '.$units[$pow];
    }
}
