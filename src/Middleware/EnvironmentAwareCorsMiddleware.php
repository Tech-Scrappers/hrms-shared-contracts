<?php

namespace Shared\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Environment-Aware CORS Middleware
 * 
 * This middleware provides environment-specific CORS configuration
 * based on the current application environment, ensuring security
 * while maintaining functionality across different deployment stages.
 */
class EnvironmentAwareCorsMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only handle CORS for API requests
        if (!$this->shouldHandleCors($request)) {
            return $response;
        }

        // Get environment-specific CORS configuration
        $corsConfig = $this->getCorsConfig();

        // Handle preflight requests
        if ($request->isMethod('OPTIONS')) {
            return $this->handlePreflightRequest($request, $corsConfig);
        }

        // Add CORS headers to actual requests
        return $this->addCorsHeaders($response, $request, $corsConfig);
    }

    /**
     * Determine if CORS should be handled for this request
     */
    private function shouldHandleCors(Request $request): bool
    {
        $corsConfig = config('cors');
        
        if (!$corsConfig['middleware']['enabled']) {
            return false;
        }

        // Check if the request path matches CORS paths
        $paths = $corsConfig['paths'] ?? ['api/*'];
        
        foreach ($paths as $path) {
            if ($request->is($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get environment-specific CORS configuration
     */
    private function getCorsConfig(): array
    {
        $environment = app()->environment();
        $baseConfig = config('cors');
        $envConfig = $baseConfig['environments'][$environment] ?? [];

        // Merge environment-specific config with base config
        return array_merge($baseConfig, $envConfig);
    }

    /**
     * Handle preflight OPTIONS requests
     */
    private function handlePreflightRequest(Request $request, array $corsConfig): Response
    {
        if (!$corsConfig['middleware']['handle_preflight']) {
            return response('', 200);
        }

        $response = response('', 200);

        // Add CORS headers
        $this->addCorsHeaders($response, $request, $corsConfig);

        return $response;
    }

    /**
     * Add CORS headers to the response
     */
    private function addCorsHeaders(Response $response, Request $request, array $corsConfig): Response
    {
        $origin = $request->header('Origin');

        // Check if origin is allowed
        if (!$this->isOriginAllowed($origin, $corsConfig)) {
            $this->logCorsViolation($request, 'Origin not allowed', $origin);
            return $response;
        }

        // Add CORS headers
        $this->addAccessControlHeaders($response, $request, $corsConfig, $origin);

        return $response;
    }

    /**
     * Check if the origin is allowed
     */
    private function isOriginAllowed(?string $origin, array $corsConfig): bool
    {
        if (!$origin) {
            return true; // Same-origin requests don't need CORS
        }

        // Check exact matches
        $allowedOrigins = $corsConfig['allowed_origins'] ?? [];
        if (in_array($origin, $allowedOrigins)) {
            return true;
        }

        // Check wildcard
        if (in_array('*', $allowedOrigins) && $corsConfig['security']['allow_wildcard_origins']) {
            return true;
        }

        // Check patterns
        $allowedPatterns = $corsConfig['allowed_origins_patterns'] ?? [];
        foreach ($allowedPatterns as $pattern) {
            if (preg_match($pattern, $origin)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add Access-Control headers to the response
     */
    private function addAccessControlHeaders(Response $response, Request $request, array $corsConfig, string $origin): void
    {
        // Access-Control-Allow-Origin
        $response->headers->set('Access-Control-Allow-Origin', $origin);

        // Access-Control-Allow-Methods
        $allowedMethods = $corsConfig['allowed_methods'] ?? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
        $response->headers->set('Access-Control-Allow-Methods', implode(', ', $allowedMethods));

        // Access-Control-Allow-Headers
        $allowedHeaders = $corsConfig['allowed_headers'] ?? [];
        $response->headers->set('Access-Control-Allow-Headers', implode(', ', $allowedHeaders));

        // Access-Control-Expose-Headers
        $exposedHeaders = $corsConfig['exposed_headers'] ?? [];
        if (!empty($exposedHeaders)) {
            $response->headers->set('Access-Control-Expose-Headers', implode(', ', $exposedHeaders));
        }

        // Access-Control-Max-Age
        $maxAge = $corsConfig['max_age'] ?? 86400;
        $response->headers->set('Access-Control-Max-Age', (string) $maxAge);

        // Access-Control-Allow-Credentials
        if ($corsConfig['supports_credentials'] ?? false) {
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }

        // Vary header for caching
        $response->headers->set('Vary', 'Origin');
    }

    /**
     * Log CORS violations
     */
    private function logCorsViolation(Request $request, string $reason, ?string $origin = null): void
    {
        $corsConfig = config('cors');
        
        if (!$corsConfig['logging']['enabled'] || !$corsConfig['logging']['log_violations']) {
            return;
        }

        $logData = [
            'reason' => $reason,
            'origin' => $origin,
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'user_agent' => $request->userAgent(),
            'ip' => $request->ip(),
            'timestamp' => now()->toISOString(),
        ];

        Log::channel($corsConfig['logging']['log_channel'])
            ->{$corsConfig['logging']['log_level']}('CORS Violation', $logData);
    }

    /**
     * Log successful CORS requests (for debugging)
     */
    private function logSuccessfulCorsRequest(Request $request, string $origin): void
    {
        $corsConfig = config('cors');
        
        if (!$corsConfig['logging']['enabled'] || !$corsConfig['logging']['log_successful_requests']) {
            return;
        }

        $logData = [
            'origin' => $origin,
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'timestamp' => now()->toISOString(),
        ];

        Log::channel($corsConfig['logging']['log_channel'])
            ->info('CORS Request Allowed', $logData);
    }
}
