<?php

namespace Shared\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class StructuredLoggingMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);
        $requestId = $this->getRequestId($request);
        
        // Log request
        $this->logRequest($request, $requestId);
        
        $response = $next($request);
        
        // Log response
        $this->logResponse($request, $response, $requestId, $startTime);
        
        return $response;
    }

    /**
     * Log incoming request
     */
    private function logRequest(Request $request, string $requestId): void
    {
        $logData = [
            'timestamp' => now()->toISOString(),
            'level' => 'INFO',
            'service' => config('app.name', 'hrms-service'),
            'version' => config('app.version', '1.0.0'),
            'request_id' => $requestId,
            'correlation_id' => $request->header('X-Correlation-ID'),
            'user_id' => $this->getUserId($request),
            'tenant_id' => $this->getTenantId($request),
            'endpoint' => $request->path(),
            'method' => $request->method(),
            'ip_address' => $this->getClientIp($request),
            'user_agent' => $request->header('User-Agent'),
            'content_type' => $request->header('Content-Type'),
            'content_length' => $request->header('Content-Length'),
            'query_params' => $this->sanitizeQueryParams($request->query()),
            'message' => 'API request received'
        ];

        // Add authentication info if available
        if ($request->has('auth_user')) {
            $logData['auth_type'] = 'oauth2';
            $logData['auth_user_id'] = $request->get('auth_user')['id'] ?? null;
        } elseif ($request->has('api_key_id')) {
            $logData['auth_type'] = 'api_key';
            $logData['api_key_id'] = $request->get('api_key_id');
        } else {
            $logData['auth_type'] = 'none';
        }

        Log::info('API Request', $logData);
    }

    /**
     * Log outgoing response
     */
    private function logResponse(Request $request, Response $response, string $requestId, float $startTime): void
    {
        $executionTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds
        $statusCode = $response->getStatusCode();
        
        $logData = [
            'timestamp' => now()->toISOString(),
            'level' => $this->getLogLevel($statusCode),
            'service' => config('app.name', 'hrms-service'),
            'version' => config('app.version', '1.0.0'),
            'request_id' => $requestId,
            'correlation_id' => $request->header('X-Correlation-ID'),
            'user_id' => $this->getUserId($request),
            'tenant_id' => $this->getTenantId($request),
            'endpoint' => $request->path(),
            'method' => $request->method(),
            'status_code' => $statusCode,
            'response_time_ms' => (int) $executionTime,
            'ip_address' => $this->getClientIp($request),
            'user_agent' => $request->header('User-Agent'),
            'message' => 'API response sent'
        ];

        // Add error details for error responses
        if ($statusCode >= 400) {
            $logData['error_code'] = $this->getErrorCode($response);
            $logData['error_message'] = $this->getErrorMessage($response);
        }

        // Add performance metrics
        $logData['performance'] = [
            'execution_time_ms' => (int) $executionTime,
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
        ];

        // Log based on status code
        if ($statusCode >= 500) {
            Log::error('API Response Error', $logData);
        } elseif ($statusCode >= 400) {
            Log::warning('API Response Client Error', $logData);
        } else {
            Log::info('API Response Success', $logData);
        }
    }

    /**
     * Get request ID
     */
    private function getRequestId(Request $request): string
    {
        return $request->header('X-Request-ID') ?: 
               $request->header('X-Correlation-ID') ?: 
               (string) \Illuminate\Support\Str::uuid();
    }

    /**
     * Get user ID from request
     */
    private function getUserId(Request $request): ?string
    {
        if ($request->has('auth_user')) {
            return $request->get('auth_user')['id'] ?? null;
        }
        
        return null;
    }

    /**
     * Get tenant ID from request
     */
    private function getTenantId(Request $request): ?string
    {
        return $request->header('X-Tenant-ID') ?: 
               $request->get('tenant_id') ?: 
               null;
    }

    /**
     * Get client IP address
     */
    private function getClientIp(Request $request): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if ($request->server($header)) {
                $ips = explode(',', $request->server($header));
                return trim($ips[0]);
            }
        }

        return $request->ip() ?? 'unknown';
    }

    /**
     * Sanitize query parameters
     */
    private function sanitizeQueryParams(array $queryParams): array
    {
        $sensitiveKeys = ['password', 'token', 'secret', 'key', 'auth'];
        
        foreach ($queryParams as $key => $value) {
            if (in_array(strtolower($key), $sensitiveKeys)) {
                $queryParams[$key] = '[REDACTED]';
            }
        }
        
        return $queryParams;
    }

    /**
     * Get log level based on status code
     */
    private function getLogLevel(int $statusCode): string
    {
        return match(true) {
            $statusCode >= 500 => 'ERROR',
            $statusCode >= 400 => 'WARNING',
            default => 'INFO'
        };
    }

    /**
     * Get error code from response
     */
    private function getErrorCode(Response $response): ?string
    {
        if ($response instanceof \Illuminate\Http\JsonResponse) {
            $data = $response->getData(true);
            return $data['error']['code'] ?? null;
        }
        
        return null;
    }

    /**
     * Get error message from response
     */
    private function getErrorMessage(Response $response): ?string
    {
        if ($response instanceof \Illuminate\Http\JsonResponse) {
            $data = $response->getData(true);
            return $data['error']['message'] ?? null;
        }
        
        return null;
    }
}
