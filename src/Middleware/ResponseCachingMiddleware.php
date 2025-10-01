<?php

namespace Shared\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class ResponseCachingMiddleware
{
    private array $cacheableMethods = ['GET', 'HEAD'];

    private array $cacheablePaths = [
        'health',
        'employees',
        'departments',
        'branches',
        'attendance',
        'leave-requests',
        'work-schedules',
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only cache GET and HEAD requests
        if (! in_array($request->method(), $this->cacheableMethods)) {
            return $next($request);
        }

        // Check if path is cacheable
        if (! $this->isCacheablePath($request->path())) {
            return $next($request);
        }

        // Check if caching is enabled
        if (! config('cache.enable_response_caching', true)) {
            return $next($request);
        }

        // Generate cache key
        $cacheKey = $this->generateCacheKey($request);

        // Try to get cached response
        $cachedResponse = $this->getCachedResponse($cacheKey);
        if ($cachedResponse) {
            return $this->createResponseFromCache($cachedResponse);
        }

        // Process request
        $response = $next($request);

        // Cache successful responses
        if ($this->shouldCacheResponse($response)) {
            $this->cacheResponse($cacheKey, $response);
        }

        return $response;
    }

    /**
     * Check if path is cacheable
     */
    private function isCacheablePath(string $path): bool
    {
        foreach ($this->cacheablePaths as $cacheablePath) {
            if (str_contains($path, $cacheablePath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate cache key with tenant context
     */
    private function generateCacheKey(Request $request): string
    {
        $tenantId = $request->header('HRMS-Client-ID', 'default');
        $path = $request->path();
        $queryString = $request->getQueryString() ?: '';
        $userAgent = $request->header('User-Agent', '');

        // Create a hash of the request parameters
        $requestHash = md5($path.$queryString.$userAgent);

        return "response_cache:tenant:{$tenantId}:{$requestHash}";
    }

    /**
     * Get cached response
     */
    private function getCachedResponse(string $cacheKey): ?array
    {
        try {
            return Cache::get($cacheKey);
        } catch (\Exception $e) {
            \Log::warning('Failed to get cached response', [
                'cache_key' => $cacheKey,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Create response from cache
     */
    private function createResponseFromCache(array $cachedData): Response
    {
        $response = response($cachedData['content'], $cachedData['status_code']);

        // Restore headers
        foreach ($cachedData['headers'] as $name => $value) {
            $response->headers->set($name, $value);
        }

        // Add cache hit header
        $response->headers->set('X-Cache', 'HIT');
        $response->headers->set('X-Cache-Key', $cachedData['cache_key']);
        $response->headers->set('X-Cache-Timestamp', $cachedData['cached_at']);

        return $response;
    }

    /**
     * Check if response should be cached
     */
    private function shouldCacheResponse(Response $response): bool
    {
        // Only cache successful responses
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return false;
        }

        // Don't cache responses with no-cache headers
        if ($response->headers->has('Cache-Control') &&
            str_contains($response->headers->get('Cache-Control'), 'no-cache')) {
            return false;
        }

        // Don't cache responses that are too large
        $contentLength = strlen($response->getContent());
        $maxCacheSize = config('cache.max_response_size', 1024 * 1024); // 1MB default

        if ($contentLength > $maxCacheSize) {
            return false;
        }

        return true;
    }

    /**
     * Cache response
     */
    private function cacheResponse(string $cacheKey, Response $response): void
    {
        try {
            $ttl = $this->getCacheTTL($response);

            $cacheData = [
                'content' => $response->getContent(),
                'status_code' => $response->getStatusCode(),
                'headers' => $response->headers->all(),
                'cache_key' => $cacheKey,
                'cached_at' => now()->toISOString(),
                'expires_at' => now()->addSeconds($ttl)->toISOString(),
            ];

            Cache::put($cacheKey, $cacheData, $ttl);

            // Add cache miss header
            $response->headers->set('X-Cache', 'MISS');
            $response->headers->set('X-Cache-TTL', $ttl);

        } catch (\Exception $e) {
            \Log::warning('Failed to cache response', [
                'cache_key' => $cacheKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get cache TTL based on response type
     */
    private function getCacheTTL(Response $response): int
    {
        $path = request()->path();

        // Health checks - short TTL
        if (str_contains($path, 'health')) {
            return 30; // 30 seconds
        }

        // Static data - longer TTL
        if (str_contains($path, 'departments') || str_contains($path, 'branches')) {
            return 300; // 5 minutes
        }

        // Dynamic data - shorter TTL
        if (str_contains($path, 'employees') || str_contains($path, 'attendance')) {
            return 60; // 1 minute
        }

        // Default TTL
        return 120; // 2 minutes
    }

    /**
     * Clear cache for specific tenant
     */
    public function clearTenantCache(string $tenantId): bool
    {
        try {
            $pattern = "response_cache:tenant:{$tenantId}:*";

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
            \Log::error('Failed to clear tenant response cache', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Clear all response cache
     */
    public function clearAllCache(): bool
    {
        try {
            $pattern = 'response_cache:tenant:*';

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
            \Log::error('Failed to clear all response cache', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
