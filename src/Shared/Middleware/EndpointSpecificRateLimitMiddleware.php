<?php

namespace Shared\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoint-Specific Rate Limiting Middleware
 * 
 * Provides granular rate limiting based on endpoint, user role, and tenant context.
 * Implements different rate limits for different types of operations.
 */
class EndpointSpecificRateLimitMiddleware
{
    /**
     * Rate limit configurations for different endpoint patterns
     */
    protected array $rateLimits = [
        // Authentication endpoints - more restrictive
        'auth.*' => [
            'max_attempts' => 5,
            'decay_minutes' => 1,
            'key_prefix' => 'auth_rate_limit',
        ],
        
        // Employee creation - moderate restriction
        'employees.store' => [
            'max_attempts' => 10,
            'decay_minutes' => 1,
            'key_prefix' => 'employee_create_rate_limit',
        ],
        
        // Employee listing - more permissive
        'employees.index' => [
            'max_attempts' => 100,
            'decay_minutes' => 1,
            'key_prefix' => 'employee_list_rate_limit',
        ],
        
        // Search endpoints - moderate restriction
        'employees.search' => [
            'max_attempts' => 50,
            'decay_minutes' => 1,
            'key_prefix' => 'search_rate_limit',
        ],
        
        // Attendance operations - moderate restriction
        'attendance.*' => [
            'max_attempts' => 20,
            'decay_minutes' => 1,
            'key_prefix' => 'attendance_rate_limit',
        ],
        
        // API key operations - very restrictive
        'api-keys.*' => [
            'max_attempts' => 3,
            'decay_minutes' => 1,
            'key_prefix' => 'api_key_rate_limit',
        ],
        
        // Tenant operations - very restrictive
        'tenants.*' => [
            'max_attempts' => 5,
            'decay_minutes' => 1,
            'key_prefix' => 'tenant_rate_limit',
        ],
        
        // Default rate limit for unmatched endpoints
        'default' => [
            'max_attempts' => 60,
            'decay_minutes' => 1,
            'key_prefix' => 'default_rate_limit',
        ],
    ];

    /**
     * Role-based rate limit multipliers
     */
    protected array $roleMultipliers = [
        'super_admin' => 2.0,    // 2x rate limit
        'tenant_admin' => 1.5,   // 1.5x rate limit
        'user' => 1.0,           // Standard rate limit
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $rateLimitConfig = $this->getRateLimitConfig($request);
        $identifier = $this->getIdentifier($request);
        
        // Apply role-based multiplier
        $multiplier = $this->getRoleMultiplier($request);
        $maxAttempts = (int) ($rateLimitConfig['max_attempts'] * $multiplier);
        
        $key = $this->getCacheKey($rateLimitConfig['key_prefix'], $identifier);
        
        // Check current attempts
        $attempts = Cache::get($key, 0);
        
        if ($attempts >= $maxAttempts) {
            $this->logRateLimitExceeded($request, $identifier, $attempts, $maxAttempts);
            
            return $this->rateLimitExceededResponse($maxAttempts, $rateLimitConfig['decay_minutes']);
        }
        
        // Increment attempts
        Cache::put($key, $attempts + 1, now()->addMinutes($rateLimitConfig['decay_minutes']));
        
        // Add rate limit headers
        $response = $next($request);
        $this->addRateLimitHeaders($response, $maxAttempts, $attempts + 1, $rateLimitConfig['decay_minutes']);
        
        return $response;
    }

    /**
     * Get rate limit configuration for the current request
     */
    protected function getRateLimitConfig(Request $request): array
    {
        $path = $request->path();
        $method = $request->method();
        
        // Build route pattern
        $routePattern = $this->buildRoutePattern($path, $method);
        
        // Find matching rate limit configuration
        foreach ($this->rateLimits as $pattern => $config) {
            if ($pattern === 'default') {
                continue;
            }
            
            if ($this->matchesPattern($routePattern, $pattern)) {
                return $config;
            }
        }
        
        // Return default configuration
        return $this->rateLimits['default'];
    }

    /**
     * Build route pattern from path and method
     */
    protected function buildRoutePattern(string $path, string $method): string
    {
        // Remove API version prefix
        $path = preg_replace('/^api\/v\d+\//', '', $path);
        
        // Convert to route pattern
        $segments = explode('/', $path);
        $pattern = '';
        
        foreach ($segments as $segment) {
            if (empty($segment)) {
                continue;
            }
            
            if (is_numeric($segment) || str_contains($segment, '{')) {
                $pattern .= '.*';
            } else {
                $pattern .= $segment . '.';
            }
        }
        
        return rtrim($pattern, '.');
    }

    /**
     * Check if route pattern matches the configured pattern
     */
    protected function matchesPattern(string $routePattern, string $configPattern): bool
    {
        // Convert config pattern to regex
        $regex = str_replace('*', '.*', $configPattern);
        $regex = '/^' . $regex . '$/';
        
        return preg_match($regex, $routePattern);
    }

    /**
     * Get unique identifier for rate limiting
     */
    protected function getIdentifier(Request $request): string
    {
        $tenantId = $request->get('tenant_id') ?? $request->header('HRMS-Client-ID');
        $userId = $request->get('user_id') ?? $request->header('X-User-ID');
        $ip = $request->ip();
        
        // Use user ID if available, otherwise IP
        $primaryId = $userId ?: $ip;
        
        // Include tenant context if available
        if ($tenantId) {
            return "{$tenantId}:{$primaryId}";
        }
        
        return $primaryId;
    }

    /**
     * Get role-based multiplier for rate limits
     */
    protected function getRoleMultiplier(Request $request): float
    {
        $user = $request->get('user') ?? $request->get('auth_user');
        $role = $user['role'] ?? 'user';
        
        return $this->roleMultipliers[$role] ?? 1.0;
    }

    /**
     * Generate cache key for rate limiting
     */
    protected function getCacheKey(string $prefix, string $identifier): string
    {
        return "{$prefix}:{$identifier}:" . now()->format('Y-m-d-H-i');
    }

    /**
     * Log rate limit exceeded event
     */
    protected function logRateLimitExceeded(Request $request, string $identifier, int $attempts, int $maxAttempts): void
    {
        Log::warning('Rate limit exceeded', [
            'endpoint' => $request->path(),
            'method' => $request->method(),
            'identifier' => $identifier,
            'attempts' => $attempts,
            'max_attempts' => $maxAttempts,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'timestamp' => now()->toISOString(),
        ]);
    }

    /**
     * Add rate limit headers to response
     */
    protected function addRateLimitHeaders(Response $response, int $maxAttempts, int $currentAttempts, int $decayMinutes): void
    {
        $remaining = max(0, $maxAttempts - $currentAttempts);
        $resetTime = now()->addMinutes($decayMinutes)->timestamp;
        
        $response->headers->set('X-RateLimit-Limit', $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', $remaining);
        $response->headers->set('X-RateLimit-Reset', $resetTime);
        $response->headers->set('X-RateLimit-Window', $decayMinutes * 60);
    }

    /**
     * Return rate limit exceeded response
     */
    protected function rateLimitExceededResponse(int $maxAttempts, int $decayMinutes): Response
    {
        $resetTime = now()->addMinutes($decayMinutes)->timestamp;
        
        return response()->json([
            'status' => 429,
            'error' => [
                'code' => 'RATE_LIMIT_EXCEEDED',
                'message' => 'Too many requests. Please try again later.',
                'details' => [
                    'limit' => $maxAttempts,
                    'window_minutes' => $decayMinutes,
                    'reset_time' => $resetTime,
                ],
            ],
            'meta' => [
                'timestamp' => now()->toISOString(),
                'retry_after' => $decayMinutes * 60,
            ],
        ], 429, [
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => 0,
            'X-RateLimit-Reset' => $resetTime,
            'Retry-After' => $decayMinutes * 60,
        ]);
    }
}
