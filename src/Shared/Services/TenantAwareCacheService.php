<?php

namespace Shared\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TenantAwareCacheService
{
    private ?string $tenantId = null;

    private string $serviceName;

    private array $cacheConfig;

    public function __construct()
    {
        $this->serviceName = config('app.name', 'hrms-service');
        $this->cacheConfig = config('cache', []);
    }

    /**
     * Set tenant context for caching
     */
    public function setTenantContext(string $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    /**
     * Get tenant-aware cache key
     */
    private function getTenantCacheKey(string $key): string
    {
        if (! $this->tenantId) {
            throw new \Exception('Tenant context not set for caching');
        }

        return "tenant:{$this->tenantId}:{$this->serviceName}:{$key}";
    }

    /**
     * Get cache value with tenant context
     */
    public function get(string $key, $default = null)
    {
        try {
            $tenantKey = $this->getTenantCacheKey($key);

            return Cache::get($tenantKey, $default);
        } catch (\Exception $e) {
            Log::warning('Cache get failed', [
                'key' => $key,
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);

            return $default;
        }
    }

    /**
     * Set cache value with tenant context
     */
    public function put(string $key, $value, $ttl = null): bool
    {
        try {
            $tenantKey = $this->getTenantCacheKey($key);

            return Cache::put($tenantKey, $value, $ttl);
        } catch (\Exception $e) {
            Log::warning('Cache put failed', [
                'key' => $key,
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Remember cache value with tenant context
     */
    public function remember(string $key, $ttl, callable $callback)
    {
        try {
            $tenantKey = $this->getTenantCacheKey($key);

            return Cache::remember($tenantKey, $ttl, $callback);
        } catch (\Exception $e) {
            Log::warning('Cache remember failed', [
                'key' => $key,
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);

            return $callback();
        }
    }

    /**
     * Forget cache value with tenant context
     */
    public function forget(string $key): bool
    {
        try {
            $tenantKey = $this->getTenantCacheKey($key);

            return Cache::forget($tenantKey);
        } catch (\Exception $e) {
            Log::warning('Cache forget failed', [
                'key' => $key,
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Clear all cache for current tenant
     */
    public function clearTenantCache(): bool
    {
        try {
            if (! $this->tenantId) {
                return false;
            }

            $pattern = "tenant:{$this->tenantId}:{$this->serviceName}:*";

            // For Redis, we can use pattern matching
            if (config('cache.default') === 'redis') {
                $redis = Cache::getRedis();
                $keys = $redis->keys($pattern);

                if (! empty($keys)) {
                    $redis->del($keys);
                }

                return true;
            }

            // For other cache drivers, we'll need to track keys
            return $this->clearTrackedKeys();
        } catch (\Exception $e) {
            Log::error('Failed to clear tenant cache', [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Clear all cache for a specific tenant (admin function)
     */
    public function clearTenantCacheById(string $tenantId): bool
    {
        try {
            $pattern = "tenant:{$tenantId}:{$this->serviceName}:*";

            if (config('cache.default') === 'redis') {
                $redis = Cache::getRedis();
                $keys = $redis->keys($pattern);

                if (! empty($keys)) {
                    $redis->del($keys);
                }

                return true;
            }

            return false;
        } catch (\Exception $e) {
            Log::error('Failed to clear tenant cache by ID', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get cache statistics for current tenant
     */
    public function getTenantCacheStats(): array
    {
        try {
            if (! $this->tenantId) {
                return [];
            }

            $pattern = "tenant:{$this->tenantId}:{$this->serviceName}:*";

            if (config('cache.default') === 'redis') {
                $redis = Cache::getRedis();
                $keys = $redis->keys($pattern);

                $totalKeys = count($keys);
                $totalMemory = 0;

                foreach ($keys as $key) {
                    $memory = $redis->memory('usage', $key);
                    $totalMemory += $memory ?: 0;
                }

                return [
                    'tenant_id' => $this->tenantId,
                    'total_keys' => $totalKeys,
                    'total_memory_bytes' => $totalMemory,
                    'total_memory_mb' => round($totalMemory / 1024 / 1024, 2),
                ];
            }

            return [
                'tenant_id' => $this->tenantId,
                'total_keys' => 0,
                'total_memory_bytes' => 0,
                'total_memory_mb' => 0,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get tenant cache stats', [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Clear tracked keys for non-Redis cache drivers
     */
    private function clearTrackedKeys(): bool
    {
        // This would require implementing a key tracking system
        // For now, we'll return false for non-Redis drivers
        return false;
    }

    /**
     * Cache database query results with tenant context
     */
    public function cacheQuery(string $queryKey, callable $queryCallback, int $ttl = 300)
    {
        return $this->remember("query:{$queryKey}", $ttl, $queryCallback);
    }

    /**
     * Cache API response with tenant context
     */
    public function cacheApiResponse(string $endpoint, array $params, callable $responseCallback, int $ttl = 60)
    {
        $cacheKey = "api:{$endpoint}:".md5(serialize($params));

        return $this->remember($cacheKey, $ttl, $responseCallback);
    }

    /**
     * Cache user session data with tenant context
     */
    public function cacheUserSession(string $userId, array $sessionData, int $ttl = 3600)
    {
        $cacheKey = "session:{$userId}";

        return $this->put($cacheKey, $sessionData, $ttl);
    }

    /**
     * Get user session data with tenant context
     */
    public function getUserSession(string $userId)
    {
        $cacheKey = "session:{$userId}";

        return $this->get($cacheKey);
    }

    /**
     * Cache tenant configuration
     */
    public function cacheTenantConfig(array $config, int $ttl = 1800)
    {
        return $this->put('config', $config, $ttl);
    }

    /**
     * Get tenant configuration
     */
    public function getTenantConfig()
    {
        return $this->get('config');
    }
}
