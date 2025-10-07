<?php

namespace Shared\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Query Cache Service
 * 
 * Provides strategic caching for frequently accessed database queries
 * with automatic cache invalidation on model updates
 */
class QueryCacheService
{
    private const DEFAULT_TTL = 3600; // 1 hour
    private const SHORT_TTL = 300;    // 5 minutes
    private const LONG_TTL = 86400;   // 24 hours

    /**
     * Get or cache a query result
     * 
     * @param string $cacheKey
     * @param callable $query
     * @param int $ttl Cache time-to-live in seconds
     * @param array $tags Cache tags for grouped invalidation
     * @return mixed
     */
    public static function remember(string $cacheKey, callable $query, int $ttl = self::DEFAULT_TTL, array $tags = [])
    {
        try {
            if (empty($tags)) {
                return Cache::remember($cacheKey, $ttl, $query);
            }

            return Cache::tags($tags)->remember($cacheKey, $ttl, $query);
        } catch (\Exception $e) {
            Log::warning('Cache operation failed, executing query directly', [
                'cache_key' => $cacheKey,
                'error' => $e->getMessage(),
            ]);

            // Fallback to direct query execution if cache fails
            return $query();
        }
    }

    /**
     * Invalidate cache by key
     */
    public static function forget(string $cacheKey): void
    {
        try {
            Cache::forget($cacheKey);
        } catch (\Exception $e) {
            Log::warning('Cache invalidation failed', [
                'cache_key' => $cacheKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Invalidate cache by tags
     */
    public static function forgetByTags(array $tags): void
    {
        try {
            Cache::tags($tags)->flush();
        } catch (\Exception $e) {
            Log::warning('Cache tag flush failed', [
                'tags' => $tags,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Generate cache key for tenant-specific data
     */
    public static function tenantKey(string $tenantId, string $suffix): string
    {
        return "tenant:{$tenantId}:{$suffix}";
    }

    /**
     * Generate cache key for employee data
     */
    public static function employeeKey(string $tenantId, string $employeeId, string $suffix = 'details'): string
    {
        return self::tenantKey($tenantId, "employee:{$employeeId}:{$suffix}");
    }

    /**
     * Generate cache key for department data
     */
    public static function departmentKey(string $tenantId, string $departmentId, string $suffix = 'details'): string
    {
        return self::tenantKey($tenantId, "department:{$departmentId}:{$suffix}");
    }

    /**
     * Generate cache key for branch data
     */
    public static function branchKey(string $tenantId, string $branchId, string $suffix = 'details'): string
    {
        return self::tenantKey($tenantId, "branch:{$branchId}:{$suffix}");
    }

    /**
     * Generate cache key for leave balance
     */
    public static function leaveBalanceKey(string $tenantId, string $employeeId, string $leaveType, int $year): string
    {
        return self::tenantKey($tenantId, "leave_balance:{$employeeId}:{$leaveType}:{$year}");
    }

    /**
     * Generate cache key for user data
     */
    public static function userKey(string $userId, string $suffix = 'details'): string
    {
        return "user:{$userId}:{$suffix}";
    }

    /**
     * Generate cache key for API key validation
     */
    public static function apiKeyKey(string $keyHash): string
    {
        return "api_key:{$keyHash}:validation";
    }

    /**
     * Get cache TTL based on data type
     */
    public static function getTTL(string $type): int
    {
        return match ($type) {
            'short' => self::SHORT_TTL,
            'long' => self::LONG_TTL,
            default => self::DEFAULT_TTL,
        };
    }

    /**
     * Generate cache tags for grouped invalidation
     */
    public static function generateTags(string $tenantId, string $resource, ?string $resourceId = null): array
    {
        $tags = [
            "tenant:{$tenantId}",
            "tenant:{$tenantId}:{$resource}",
        ];

        if ($resourceId) {
            $tags[] = "tenant:{$tenantId}:{$resource}:{$resourceId}";
        }

        return $tags;
    }

    /**
     * Invalidate all cache for a tenant
     */
    public static function invalidateTenant(string $tenantId): void
    {
        self::forgetByTags(["tenant:{$tenantId}"]);
    }

    /**
     * Invalidate all cache for a tenant resource type
     */
    public static function invalidateTenantResource(string $tenantId, string $resource): void
    {
        self::forgetByTags(["tenant:{$tenantId}:{$resource}"]);
    }

    /**
     * Invalidate cache for a specific tenant resource
     */
    public static function invalidateTenantResourceItem(string $tenantId, string $resource, string $resourceId): void
    {
        self::forgetByTags(["tenant:{$tenantId}:{$resource}:{$resourceId}"]);
    }
}
