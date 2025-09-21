<?php

namespace Shared\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

class EnhancedRateLimitMiddleware
{
    private const RATE_LIMIT_PREFIX = 'rate_limit_';
    private const RATE_LIMIT_TTL = 3600; // 1 hour
    private const DEFAULT_LIMIT = 1000; // requests per hour
    private const BURST_LIMIT = 50; // requests per minute (reduced for testing)
    private const BURST_TTL = 60; // 1 minute

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $identifier = $this->getRateLimitIdentifier($request);
        
        // Check burst limit (per minute)
        if ($this->isBurstLimited($identifier)) {
            return $this->rateLimitedResponse('Burst rate limit exceeded', 60);
        }

        // Check general rate limit (per hour)
        if ($this->isRateLimited($identifier)) {
            return $this->rateLimitedResponse('Rate limit exceeded', 3600);
        }

        // Increment counters
        $this->incrementCounters($identifier);

        // Add rate limit headers to response
        $response = $next($request);
        $this->addRateLimitHeaders($response, $identifier);

        return $response;
    }

    /**
     * Get rate limit identifier based on authentication method
     */
    private function getRateLimitIdentifier(Request $request): string
    {
        // Use API key if present
        $apiKey = $request->header('HRMS-Client-Secret');
        if ($apiKey) {
            return 'api_key_' . hash('sha256', $apiKey);
        }

        // Use OAuth2 token if present
        $authHeader = $request->header('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
            return 'oauth_' . hash('sha256', $token);
        }

        // Use IP address as fallback
        return 'ip_' . $request->ip();
    }

    /**
     * Check if request is burst limited (per minute)
     */
    private function isBurstLimited(string $identifier): bool
    {
        $key = self::RATE_LIMIT_PREFIX . 'burst_' . $identifier;
        $count = Cache::get($key, 0);

        return $count >= self::BURST_LIMIT;
    }

    /**
     * Check if request is rate limited (per hour)
     */
    private function isRateLimited(string $identifier): bool
    {
        $key = self::RATE_LIMIT_PREFIX . 'hourly_' . $identifier;
        $count = Cache::get($key, 0);

        return $count >= self::DEFAULT_LIMIT;
    }

    /**
     * Increment rate limit counters
     */
    private function incrementCounters(string $identifier): void
    {
        // Increment burst counter (per minute)
        $burstKey = self::RATE_LIMIT_PREFIX . 'burst_' . $identifier;
        Cache::increment($burstKey);
        Cache::put($burstKey, Cache::get($burstKey, 0), self::BURST_TTL);

        // Increment hourly counter
        $hourlyKey = self::RATE_LIMIT_PREFIX . 'hourly_' . $identifier;
        Cache::increment($hourlyKey);
        Cache::put($hourlyKey, Cache::get($hourlyKey, 0), self::RATE_LIMIT_TTL);

        // Log high usage
        $hourlyCount = Cache::get($hourlyKey, 0);
        if ($hourlyCount > (self::DEFAULT_LIMIT * 0.8)) {
            Log::warning('High rate limit usage detected', [
                'identifier' => $identifier,
                'hourly_count' => $hourlyCount,
                'limit' => self::DEFAULT_LIMIT,
                'percentage' => round(($hourlyCount / self::DEFAULT_LIMIT) * 100, 2),
            ]);
        }
    }

    /**
     * Add rate limit headers to response
     */
    private function addRateLimitHeaders(Response $response, string $identifier): void
    {
        $burstKey = self::RATE_LIMIT_PREFIX . 'burst_' . $identifier;
        $hourlyKey = self::RATE_LIMIT_PREFIX . 'hourly_' . $identifier;

        $burstCount = Cache::get($burstKey, 0);
        $hourlyCount = Cache::get($hourlyKey, 0);

        $response->headers->set('X-RateLimit-Burst-Limit', self::BURST_LIMIT);
        $response->headers->set('X-RateLimit-Burst-Remaining', max(0, self::BURST_LIMIT - $burstCount));
        $response->headers->set('X-RateLimit-Burst-Reset', now()->addSeconds(self::BURST_TTL)->timestamp);

        $response->headers->set('X-RateLimit-Hourly-Limit', self::DEFAULT_LIMIT);
        $response->headers->set('X-RateLimit-Hourly-Remaining', max(0, self::DEFAULT_LIMIT - $hourlyCount));
        $response->headers->set('X-RateLimit-Hourly-Reset', now()->addSeconds(self::RATE_LIMIT_TTL)->timestamp);
    }

    /**
     * Return rate limited response
     */
    private function rateLimitedResponse(string $message, int $retryAfter): Response
    {
        Log::warning('Rate limit exceeded', [
            'message' => $message,
            'retry_after' => $retryAfter,
            'timestamp' => now()->toISOString(),
        ]);

        return response()->json([
            'success' => false,
            'message' => $message,
            'error_code' => 'RATE_LIMIT_EXCEEDED',
            'retry_after' => $retryAfter,
            'timestamp' => now()->toISOString(),
        ], 429)->header('Retry-After', $retryAfter);
    }

    /**
     * Get current rate limit status for identifier
     */
    public static function getRateLimitStatus(string $identifier): array
    {
        $burstKey = self::RATE_LIMIT_PREFIX . 'burst_' . $identifier;
        $hourlyKey = self::RATE_LIMIT_PREFIX . 'hourly_' . $identifier;

        $burstCount = Cache::get($burstKey, 0);
        $hourlyCount = Cache::get($hourlyKey, 0);

        return [
            'burst' => [
                'limit' => self::BURST_LIMIT,
                'used' => $burstCount,
                'remaining' => max(0, self::BURST_LIMIT - $burstCount),
                'reset_at' => now()->addSeconds(self::BURST_TTL)->toISOString(),
            ],
            'hourly' => [
                'limit' => self::DEFAULT_LIMIT,
                'used' => $hourlyCount,
                'remaining' => max(0, self::DEFAULT_LIMIT - $hourlyCount),
                'reset_at' => now()->addSeconds(self::RATE_LIMIT_TTL)->toISOString(),
            ],
        ];
    }

    /**
     * Reset rate limit for identifier
     */
    public static function resetRateLimit(string $identifier): void
    {
        $burstKey = self::RATE_LIMIT_PREFIX . 'burst_' . $identifier;
        $hourlyKey = self::RATE_LIMIT_PREFIX . 'hourly_' . $identifier;

        Cache::forget($burstKey);
        Cache::forget($hourlyKey);

        Log::info('Rate limit reset for identifier', [
            'identifier' => $identifier,
            'timestamp' => now()->toISOString(),
        ]);
    }
}
