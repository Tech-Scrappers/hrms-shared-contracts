<?php

namespace Shared\Middleware;

use Closure;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ApiKeyRateLimitMiddleware
{
    private const RATE_LIMIT_PREFIX = 'api_rate_limit_';

    private const DEFAULT_LIMIT = 1000; // requests per hour

    private const DEFAULT_WINDOW = 3600; // 1 hour in seconds

    public function __construct()
    {
        // Middleware can be configured per route
    }

    /**
     * Handle an incoming request.
     *
     * @return Response
     */
    public function handle(Request $request, Closure $next, int $maxRequests = self::DEFAULT_LIMIT, int $windowSeconds = self::DEFAULT_WINDOW): \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
    {
        try {
            $apiKey = $this->extractApiKey($request);

            if (! $apiKey) {
                // If no API key, use IP-based rate limiting
                $identifier = $request->ip();
            } else {
                // Use API key for rate limiting
                $identifier = hash('sha256', $apiKey);
            }

            $rateLimitKey = self::RATE_LIMIT_PREFIX.$identifier;

            // Get current request count
            $currentRequests = Cache::get($rateLimitKey, 0);

            // Check if rate limit exceeded
            if ($currentRequests >= $maxRequests) {
                Log::warning('Rate limit exceeded', [
                    'identifier' => substr($identifier, 0, 10).'...',
                    'current_requests' => $currentRequests,
                    'max_requests' => $maxRequests,
                    'window_seconds' => $windowSeconds,
                    'request_uri' => $request->getRequestUri(),
                    'ip' => $request->ip(),
                ]);

                return $this->rateLimitedResponse($maxRequests, $windowSeconds);
            }

            // Increment request count
            Cache::put($rateLimitKey, $currentRequests + 1, $windowSeconds);

            // Add rate limit headers to response
            $response = $next($request);

            $this->addRateLimitHeaders($response, $currentRequests + 1, $maxRequests, $windowSeconds);

            return $response;

        } catch (Exception $e) {
            Log::error('Rate limiting error', [
                'error' => $e->getMessage(),
                'request_uri' => $request->getRequestUri(),
            ]);

            // Continue with request if rate limiting fails
            return $next($request);
        }
    }

    /**
     * Extract API key from request
     */
    private function extractApiKey(Request $request): ?string
    {
        // Check Authorization header (Bearer token format)
        $authHeader = $request->header('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }

        // Check HRMS-Client-Secret header
        $apiKeyHeader = $request->header('HRMS-Client-Secret');
        if ($apiKeyHeader) {
            return $apiKeyHeader;
        }

        // Check query parameter
        $apiKeyParam = $request->query('api_key');
        if ($apiKeyParam) {
            return $apiKeyParam;
        }

        return null;
    }

    /**
     * Add rate limit headers to response
     *
     * @param  Response  $response
     */
    private function addRateLimitHeaders(\Illuminate\Http\Response|\Illuminate\Http\JsonResponse $response, int $currentRequests, int $maxRequests, int $windowSeconds): void
    {
        $response->headers->set('X-RateLimit-Limit', $maxRequests);
        $response->headers->set('X-RateLimit-Remaining', max(0, $maxRequests - $currentRequests));
        $response->headers->set('X-RateLimit-Reset', now()->addSeconds($windowSeconds)->timestamp);
    }

    /**
     * Return rate limited response
     */
    private function rateLimitedResponse(int $maxRequests, int $windowSeconds): Response
    {
        $response = response()->json([
            'success' => false,
            'message' => 'Rate limit exceeded',
            'error_code' => 'RATE_LIMITED',
            'retry_after' => $windowSeconds,
        ], 429);

        $this->addRateLimitHeaders($response, $maxRequests, $maxRequests, $windowSeconds);

        return $response;
    }
}
