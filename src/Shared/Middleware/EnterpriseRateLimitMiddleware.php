<?php

namespace Shared\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnterpriseRateLimitMiddleware
{
    /**
     * Rate limit configurations
     */
    private array $rateLimits = [
        'anonymous' => [
            'requests_per_minute' => 60,
            'requests_per_hour' => 1000,
            'burst_limit' => 10
        ],
        'authenticated' => [
            'requests_per_minute' => 200,
            'requests_per_hour' => 5000,
            'burst_limit' => 50
        ],
        'api_key' => [
            'requests_per_minute' => 500,
            'requests_per_hour' => 10000,
            'burst_limit' => 100
        ],
        'premium' => [
            'requests_per_minute' => 1000,
            'requests_per_hour' => 50000,
            'burst_limit' => 200
        ],
        'internal' => [
            'requests_per_minute' => 2000,
            'requests_per_hour' => 100000,
            'burst_limit' => 500
        ]
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $tier = 'authenticated'): Response
    {
        $identifier = $this->getRateLimitIdentifier($request);
        $tier = $this->determineTier($request, $tier);
        
        // Check rate limits
        $rateLimitResult = $this->checkRateLimit($identifier, $tier, $request);
        
        if (!$rateLimitResult['allowed']) {
            return $this->rateLimitResponse($rateLimitResult);
        }

        // Add rate limit headers to response
        $response = $next($request);
        $this->addRateLimitHeaders($response, $rateLimitResult);

        return $response;
    }

    /**
     * Get rate limit identifier for the request
     */
    private function getRateLimitIdentifier(Request $request): string
    {
        // For authenticated users, use user ID
        if ($request->has('auth_user')) {
            $user = $request->get('auth_user');
            return 'user:' . ($user['id'] ?? 'unknown');
        }

        // For API key requests, use API key ID
        if ($request->has('api_key_id')) {
            return 'api_key:' . $request->get('api_key_id');
        }

        // For internal services, use service identifier
        if ($this->isInternalRequest($request)) {
            return 'internal:' . $request->header('X-Service-Name', 'unknown');
        }

        // For anonymous users, use IP address
        return 'ip:' . $this->getClientIp($request);
    }

    /**
     * Determine the rate limit tier for the request
     */
    private function determineTier(Request $request, string $defaultTier): string
    {
        // Check for premium API key
        if ($request->has('api_key_tier') && $request->get('api_key_tier') === 'premium') {
            return 'premium';
        }

        // Check for internal service
        if ($this->isInternalRequest($request)) {
            return 'internal';
        }

        // Check for API key
        if ($request->has('api_key_id')) {
            return 'api_key';
        }

        // Check for authenticated user
        if ($request->has('auth_user')) {
            return 'authenticated';
        }

        return $defaultTier;
    }

    /**
     * Check rate limits for the identifier
     */
    private function checkRateLimit(string $identifier, string $tier, Request $request): array
    {
        $limits = $this->rateLimits[$tier] ?? $this->rateLimits['authenticated'];
        $now = now();
        
        // Check minute-based rate limit
        $minuteKey = "rate_limit:minute:{$identifier}:" . $now->format('Y-m-d-H-i');
        $minuteCount = Cache::get($minuteKey, 0);
        
        if ($minuteCount >= $limits['requests_per_minute']) {
            return [
                'allowed' => false,
                'limit' => $limits['requests_per_minute'],
                'remaining' => 0,
                'reset_time' => $now->addMinute()->timestamp,
                'retry_after' => 60,
                'tier' => $tier,
                'window' => 'minute'
            ];
        }

        // Check hour-based rate limit
        $hourKey = "rate_limit:hour:{$identifier}:" . $now->format('Y-m-d-H');
        $hourCount = Cache::get($hourKey, 0);
        
        if ($hourCount >= $limits['requests_per_hour']) {
            return [
                'allowed' => false,
                'limit' => $limits['requests_per_hour'],
                'remaining' => 0,
                'reset_time' => $now->addHour()->timestamp,
                'retry_after' => 3600,
                'tier' => $tier,
                'window' => 'hour'
            ];
        }

        // Check burst limit (sliding window)
        $burstKey = "rate_limit:burst:{$identifier}";
        $burstCount = Cache::get($burstKey, 0);
        
        if ($burstCount >= $limits['burst_limit']) {
            return [
                'allowed' => false,
                'limit' => $limits['burst_limit'],
                'remaining' => 0,
                'reset_time' => $now->addSeconds(10)->timestamp,
                'retry_after' => 10,
                'tier' => $tier,
                'window' => 'burst'
            ];
        }

        // Increment counters
        $this->incrementCounters($identifier, $minuteKey, $hourKey, $burstKey);

        return [
            'allowed' => true,
            'limit' => $limits['requests_per_minute'],
            'remaining' => $limits['requests_per_minute'] - $minuteCount - 1,
            'reset_time' => $now->addMinute()->timestamp,
            'tier' => $tier,
            'window' => 'minute'
        ];
    }

    /**
     * Increment rate limit counters
     */
    private function incrementCounters(string $identifier, string $minuteKey, string $hourKey, string $burstKey): void
    {
        // Increment minute counter
        Cache::increment($minuteKey);
        Cache::put($minuteKey, Cache::get($minuteKey, 0), now()->addMinute());

        // Increment hour counter
        Cache::increment($hourKey);
        Cache::put($hourKey, Cache::get($hourKey, 0), now()->addHour());

        // Increment burst counter (10-second sliding window)
        Cache::increment($burstKey);
        Cache::put($burstKey, Cache::get($burstKey, 0), now()->addSeconds(10));
    }

    /**
     * Generate rate limit exceeded response
     */
    private function rateLimitResponse(array $rateLimitResult): Response
    {
        $response = response()->json([
            'status' => 429,
            'error' => [
                'code' => 'RATE_LIMIT_EXCEEDED',
                'message' => 'Too many requests. Please try again later',
                'type' => 'rate_limit_error'
            ],
            'meta' => [
                'timestamp' => now()->toISOString(),
                'request_id' => request()->header('X-Request-ID', (string) \Illuminate\Support\Str::uuid()),
                'rate_limit' => [
                    'limit' => $rateLimitResult['limit'],
                    'remaining' => $rateLimitResult['remaining'],
                    'reset_time' => now()->createFromTimestamp($rateLimitResult['reset_time'])->toISOString(),
                    'retry_after' => $rateLimitResult['retry_after'],
                    'tier' => $rateLimitResult['tier'],
                    'window' => $rateLimitResult['window']
                ]
            ]
        ], 429);

        // Add rate limit headers
        $response->headers->set('X-RateLimit-Limit', $rateLimitResult['limit']);
        $response->headers->set('X-RateLimit-Remaining', $rateLimitResult['remaining']);
        $response->headers->set('X-RateLimit-Reset', $rateLimitResult['reset_time']);
        $response->headers->set('Retry-After', $rateLimitResult['retry_after']);

        // Log rate limit exceeded
        Log::warning('Rate limit exceeded', [
            'identifier' => $this->getRateLimitIdentifier(request()),
            'tier' => $rateLimitResult['tier'],
            'limit' => $rateLimitResult['limit'],
            'window' => $rateLimitResult['window'],
            'ip' => $this->getClientIp(request()),
            'user_agent' => request()->header('User-Agent'),
            'endpoint' => request()->path()
        ]);

        return $response;
    }

    /**
     * Add rate limit headers to response
     */
    private function addRateLimitHeaders(Response $response, array $rateLimitResult): void
    {
        $response->headers->set('X-RateLimit-Limit', $rateLimitResult['limit']);
        $response->headers->set('X-RateLimit-Remaining', $rateLimitResult['remaining']);
        $response->headers->set('X-RateLimit-Reset', $rateLimitResult['reset_time']);
        $response->headers->set('X-RateLimit-Tier', $rateLimitResult['tier']);
    }

    /**
     * Check if request is from internal service
     */
    private function isInternalRequest(Request $request): bool
    {
        $internalServices = [
            'identity-service',
            'employee-service',
            'attendance-service',
            'api-gateway'
        ];

        $serviceName = $request->header('X-Service-Name');
        $userAgent = $request->header('User-Agent', '');

        return in_array($serviceName, $internalServices) || 
               str_contains($userAgent, 'HRMS-Internal');
    }

    /**
     * Get client IP address
     */
    private function getClientIp(Request $request): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',      // Load balancer/proxy
            'HTTP_X_FORWARDED',          // Proxy
            'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster
            'HTTP_FORWARDED_FOR',        // Proxy
            'HTTP_FORWARDED',            // Proxy
            'REMOTE_ADDR'                // Standard
        ];

        foreach ($headers as $header) {
            if ($request->server($header)) {
                $ips = explode(',', $request->server($header));
                return trim($ips[0]);
            }
        }

        return $request->ip() ?? 'unknown';
    }
}
