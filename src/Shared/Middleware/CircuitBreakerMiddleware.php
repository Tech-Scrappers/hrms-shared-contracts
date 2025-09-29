<?php

namespace Shared\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Shared\Services\CircuitBreakerService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Circuit Breaker Middleware
 * 
 * Implements circuit breaker pattern for external service calls
 * to prevent cascade failures and improve system resilience.
 */
class CircuitBreakerMiddleware
{
    protected CircuitBreakerService $circuitBreaker;

    public function __construct()
    {
        $this->circuitBreaker = new CircuitBreakerService(
            serviceName: 'external_service',
            failureThreshold: 5,
            recoveryTimeout: 60,
            successThreshold: 3,
            requestTimeout: 30
        );
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only apply circuit breaker to external service calls
        if (!$this->isExternalServiceCall($request)) {
            return $next($request);
        }

        $serviceName = $this->getServiceName($request);
        $circuitBreaker = new CircuitBreakerService($serviceName);

        try {
            return $circuitBreaker->execute(
                operation: function () use ($next, $request) {
                    return $next($request);
                },
                fallback: function (\Exception $exception, array $context) use ($request) {
                    return $this->handleFallback($request, $exception, $context);
                },
                context: [
                    'endpoint' => $request->path(),
                    'method' => $request->method(),
                    'ip' => $request->ip(),
                ]
            );
        } catch (\Exception $e) {
            Log::error('Circuit breaker middleware: Unhandled exception', [
                'service' => $serviceName,
                'error' => $e->getMessage(),
                'endpoint' => $request->path(),
            ]);

            return $this->handleFallback($request, $e, []);
        }
    }

    /**
     * Check if this is an external service call
     */
    protected function isExternalServiceCall(Request $request): bool
    {
        $path = $request->path();
        
        // Define patterns for external service calls
        $externalPatterns = [
            'api/v1/auth/validate-token',
            'api/v1/tenants/',
            'api/v1/api-keys/',
        ];

        foreach ($externalPatterns as $pattern) {
            if (str_starts_with($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get service name from request
     */
    protected function getServiceName(Request $request): string
    {
        $path = $request->path();
        
        if (str_contains($path, 'auth')) {
            return 'identity_service';
        }
        
        if (str_contains($path, 'tenants')) {
            return 'tenant_service';
        }
        
        if (str_contains($path, 'api-keys')) {
            return 'api_key_service';
        }

        return 'external_service';
    }

    /**
     * Handle fallback response when circuit is open
     */
    protected function handleFallback(Request $request, \Exception $exception, array $context): Response
    {
        Log::warning('Circuit breaker: Using fallback response', [
            'endpoint' => $request->path(),
            'method' => $request->method(),
            'error' => $exception->getMessage(),
            'context' => $context,
        ]);

        // Return appropriate fallback response based on endpoint
        if (str_contains($request->path(), 'auth')) {
            return $this->authFallbackResponse();
        }

        if (str_contains($request->path(), 'tenants')) {
            return $this->tenantFallbackResponse();
        }

        if (str_contains($request->path(), 'api-keys')) {
            return $this->apiKeyFallbackResponse();
        }

        return $this->genericFallbackResponse();
    }

    /**
     * Authentication service fallback response
     */
    protected function authFallbackResponse(): Response
    {
        return response()->json([
            'status' => 503,
            'error' => [
                'code' => 'SERVICE_UNAVAILABLE',
                'message' => 'Authentication service is temporarily unavailable. Please try again later.',
            ],
            'meta' => [
                'timestamp' => now()->toISOString(),
                'retry_after' => 60,
            ],
        ], 503);
    }

    /**
     * Tenant service fallback response
     */
    protected function tenantFallbackResponse(): Response
    {
        return response()->json([
            'status' => 503,
            'error' => [
                'code' => 'SERVICE_UNAVAILABLE',
                'message' => 'Tenant service is temporarily unavailable. Please try again later.',
            ],
            'meta' => [
                'timestamp' => now()->toISOString(),
                'retry_after' => 60,
            ],
        ], 503);
    }

    /**
     * API key service fallback response
     */
    protected function apiKeyFallbackResponse(): Response
    {
        return response()->json([
            'status' => 503,
            'error' => [
                'code' => 'SERVICE_UNAVAILABLE',
                'message' => 'API key service is temporarily unavailable. Please try again later.',
            ],
            'meta' => [
                'timestamp' => now()->toISOString(),
                'retry_after' => 60,
            ],
        ], 503);
    }

    /**
     * Generic fallback response
     */
    protected function genericFallbackResponse(): Response
    {
        return response()->json([
            'status' => 503,
            'error' => [
                'code' => 'SERVICE_UNAVAILABLE',
                'message' => 'Service is temporarily unavailable. Please try again later.',
            ],
            'meta' => [
                'timestamp' => now()->toISOString(),
                'retry_after' => 60,
            ],
        ], 503);
    }
}
