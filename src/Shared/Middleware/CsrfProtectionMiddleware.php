<?php

namespace Shared\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CsrfProtectionMiddleware
{
    private const CSRF_TOKEN_LENGTH = 40;
    private const CSRF_TOKEN_TTL = 3600; // 1 hour
    private const CACHE_PREFIX = 'csrf_token_';

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip CSRF protection for safe methods and health checks
        if ($this->shouldSkipCsrfProtection($request)) {
            return $next($request);
        }

        // Skip CSRF protection for API key authenticated requests
        if ($request->hasHeader('X-API-Key')) {
            return $next($request);
        }

        // Skip CSRF protection for OAuth2 authenticated requests
        if ($request->hasHeader('Authorization') && 
            str_starts_with($request->header('Authorization'), 'Bearer ')) {
            return $next($request);
        }

        // Validate CSRF token for state-changing operations
        if (!$this->validateCsrfToken($request)) {
            Log::warning('CSRF token validation failed', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'timestamp' => now()->toISOString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'CSRF token mismatch',
                'error_code' => 'CSRF_TOKEN_MISMATCH',
                'timestamp' => now()->toISOString(),
            ], 419);
        }

        return $next($request);
    }

    /**
     * Check if CSRF protection should be skipped
     */
    private function shouldSkipCsrfProtection(Request $request): bool
    {
        // Skip for safe methods
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'])) {
            return true;
        }

        // Skip for health check endpoints
        if ($request->is('health') || $request->is('api/v1/health')) {
            return true;
        }

        // Skip for webhook endpoints
        if ($request->is('webhooks/*')) {
            return true;
        }

        // Skip for file upload endpoints (handled separately)
        if ($request->is('api/v1/upload/*')) {
            return true;
        }

        return false;
    }

    /**
     * Validate CSRF token
     */
    private function validateCsrfToken(Request $request): bool
    {
        $token = $this->extractCsrfToken($request);
        
        if (!$token) {
            return false;
        }

        // Check if token exists in cache
        $cacheKey = self::CACHE_PREFIX . $token;
        $cachedData = Cache::get($cacheKey);

        if (!$cachedData) {
            return false;
        }

        // Validate token format
        if (!is_string($cachedData) || strlen($cachedData) !== self::CSRF_TOKEN_LENGTH) {
            return false;
        }

        // Validate token matches
        if ($cachedData !== $token) {
            return false;
        }

        // Check if token is not expired
        $tokenData = Cache::get($cacheKey . '_data');
        if (!$tokenData || now()->isAfter($tokenData['expires_at'])) {
            Cache::forget($cacheKey);
            Cache::forget($cacheKey . '_data');
            return false;
        }

        return true;
    }

    /**
     * Extract CSRF token from request
     */
    private function extractCsrfToken(Request $request): ?string
    {
        // Check X-CSRF-TOKEN header
        $token = $request->header('X-CSRF-TOKEN');
        if ($token) {
            return $token;
        }

        // Check X-XSRF-TOKEN header (for encrypted cookies)
        $xsrfToken = $request->header('X-XSRF-TOKEN');
        if ($xsrfToken) {
            try {
                return decrypt($xsrfToken);
            } catch (\Exception $e) {
                Log::warning('Failed to decrypt XSRF token', [
                    'error' => $e->getMessage(),
                    'ip' => $request->ip(),
                ]);
                return null;
            }
        }

        // Check request body
        $token = $request->input('_token');
        if ($token) {
            return $token;
        }

        return null;
    }

    /**
     * Generate a new CSRF token
     */
    public static function generateToken(): string
    {
        return Str::random(self::CSRF_TOKEN_LENGTH);
    }

    /**
     * Store CSRF token in cache
     */
    public static function storeToken(string $token, int $ttl = self::CSRF_TOKEN_TTL): void
    {
        $cacheKey = self::CACHE_PREFIX . $token;
        
        Cache::put($cacheKey, $token, $ttl);
        Cache::put($cacheKey . '_data', [
            'created_at' => now()->toISOString(),
            'expires_at' => now()->addSeconds($ttl)->toISOString(),
        ], $ttl);
    }

    /**
     * Revoke CSRF token
     */
    public static function revokeToken(string $token): void
    {
        $cacheKey = self::CACHE_PREFIX . $token;
        
        Cache::forget($cacheKey);
        Cache::forget($cacheKey . '_data');
    }

    /**
     * Get CSRF token for response
     */
    public static function getTokenForResponse(): array
    {
        $token = self::generateToken();
        self::storeToken($token);

        return [
            'csrf_token' => $token,
            'expires_at' => now()->addSeconds(self::CSRF_TOKEN_TTL)->toISOString(),
        ];
    }
}
