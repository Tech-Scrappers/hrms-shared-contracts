<?php

namespace Shared\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Shared\Services\ApiKeyService;
use Shared\Services\TenantDatabaseService;
use Exception;

class ApiKeyAuthenticationMiddleware
{
    private const CACHE_PREFIX = 'api_key_';
    private const CACHE_TTL = 300; // 5 minutes
    private const RATE_LIMIT_PREFIX = 'rate_limit_';
    private const RATE_LIMIT_TTL = 3600; // 1 hour
    private const MAX_REQUESTS_PER_HOUR = 1000;

    public function __construct(
        private ApiKeyService $apiKeyService,
        private TenantDatabaseService $tenantDatabaseService
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @return JsonResponse
     */
    public function handle(Request $request, Closure $next): \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
    {
        try {
            // Extract API key from request
            $apiKey = $this->extractApiKey($request);
            
            if (!$apiKey) {
                return $this->unauthorizedResponse('API key is required');
            }

            // Validate API key format
            if (!$this->isValidApiKeyFormat($apiKey)) {
                return $this->unauthorizedResponse('Invalid API key format');
            }

            // Check rate limiting
            if ($this->isRateLimited($apiKey)) {
                return $this->rateLimitedResponse('Rate limit exceeded');
            }

            // Validate API key and get tenant information
            $apiKeyData = $this->validateApiKey($apiKey);
            
            if (!$apiKeyData) {
                return $this->unauthorizedResponse('Invalid API key');
            }

            // Check if API key is active and not expired
            if (!$this->isApiKeyValid($apiKeyData)) {
                return $this->unauthorizedResponse('API key is inactive or expired');
            }

            // Get tenant information
            $tenant = $this->getTenantInformation($apiKeyData['tenant_id']);
            
            if (!$tenant) {
                return $this->unauthorizedResponse('Tenant not found');
            }

            // Check if tenant is active
            if (!$tenant['is_active']) {
                return $this->unauthorizedResponse('Tenant is inactive');
            }

            // Switch to tenant database
            $this->tenantDatabaseService->switchToTenantDatabase($tenant['id']);

            // Add authentication context to request
            $this->addAuthenticationContext($request, $apiKeyData, $tenant);

            // Log API key usage
            $this->logApiKeyUsage($apiKeyData, $request);

            // Update last used timestamp
            $this->updateLastUsedTimestamp($apiKeyData['id']);

            // Process request
            $response = $next($request);

            // Switch back to central database
            $this->tenantDatabaseService->switchToCentralDatabase();

            return $response;

        } catch (Exception $e) {
            Log::error('API key authentication error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_uri' => $request->getRequestUri(),
                'ip' => $request->ip(),
            ]);

            return $this->serverErrorResponse('Authentication service error');
        }
    }

    /**
     * Extract API key from request headers or query parameters
     *
     * @param Request $request
     * @return string|null
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
     * Validate API key format
     *
     * @param string $apiKey
     * @return bool
     */
    private function isValidApiKeyFormat(string $apiKey): bool
    {
        // API key should start with 'ak_' and be 67 characters long (3 + 64 hex chars)
        return str_starts_with($apiKey, 'ak_') && strlen($apiKey) === 67;
    }

    /**
     * Check if API key is rate limited
     *
     * @param string $apiKey
     * @return bool
     */
    private function isRateLimited(string $apiKey): bool
    {
        $cacheKey = self::RATE_LIMIT_PREFIX . hash('sha256', $apiKey);
        $requestCount = Cache::get($cacheKey, 0);

        if ($requestCount >= self::MAX_REQUESTS_PER_HOUR) {
            return true;
        }

        Cache::put($cacheKey, $requestCount + 1, self::RATE_LIMIT_TTL);
        return false;
    }

    /**
     * Validate API key and return key data
     *
     * @param string $apiKey
     * @return array|null
     */
    private function validateApiKey(string $apiKey): ?array
    {
        $cacheKey = self::CACHE_PREFIX . hash('sha256', $apiKey);
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($apiKey) {
            return $this->apiKeyService->validateApiKey($apiKey);
        });
    }

    /**
     * Check if API key is valid (active and not expired)
     *
     * @param array $apiKeyData
     * @return bool
     */
    private function isApiKeyValid(array $apiKeyData): bool
    {
        // Check if API key is active
        if (!$apiKeyData['is_active']) {
            return false;
        }

        // Check if API key is expired
        if ($apiKeyData['expires_at'] && now()->isAfter($apiKeyData['expires_at'])) {
            return false;
        }

        return true;
    }

    /**
     * Get tenant information
     *
     * @param string $tenantId
     * @return array|null
     */
    private function getTenantInformation(string $tenantId): ?array
    {
        return $this->tenantDatabaseService->getTenant($tenantId);
    }

    /**
     * Add authentication context to request
     *
     * @param Request $request
     * @param array $apiKeyData
     * @param array $tenant
     * @return void
     */
    private function addAuthenticationContext(Request $request, array $apiKeyData, array $tenant): void
    {
        // Add API key data to request
        $request->merge([
            'api_key_id' => $apiKeyData['id'],
            'api_key_name' => $apiKeyData['name'],
            'api_key_permissions' => $apiKeyData['permissions'] ?? [],
        ]);

        // Add tenant context
        $request->merge([
            'tenant_id' => $tenant['id'],
            'tenant_domain' => $tenant['domain'],
            'tenant_name' => $tenant['name'],
        ]);

        // Add to request attributes for easy access
        $request->attributes->set('api_key', $apiKeyData);
        $request->attributes->set('tenant', $tenant);
    }

    /**
     * Log API key usage
     *
     * @param array $apiKeyData
     * @param Request $request
     * @return void
     */
    private function logApiKeyUsage(array $apiKeyData, Request $request): void
    {
        Log::channel('audit')->info('API Key Usage', [
            'api_key_id' => $apiKeyData['id'],
            'api_key_name' => $apiKeyData['name'],
            'tenant_id' => $apiKeyData['tenant_id'],
            'method' => $request->method(),
            'uri' => $request->getRequestUri(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'timestamp' => now()->toISOString(),
        ]);
    }

    /**
     * Update last used timestamp for API key
     *
     * @param string $apiKeyId
     * @return void
     */
    private function updateLastUsedTimestamp(string $apiKeyId): void
    {
        try {
            $this->apiKeyService->updateLastUsed($apiKeyId);
        } catch (Exception $e) {
            Log::warning('Failed to update API key last used timestamp', [
                'api_key_id' => $apiKeyId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Return unauthorized response
     *
     * @param string $message
     * @return JsonResponse
     */
    private function unauthorizedResponse(string $message): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error_code' => 'UNAUTHORIZED',
        ], 401);
    }

    /**
     * Return rate limited response
     *
     * @param string $message
     * @return JsonResponse
     */
    private function rateLimitedResponse(string $message): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error_code' => 'RATE_LIMITED',
            'retry_after' => self::RATE_LIMIT_TTL,
        ], 429);
    }

    /**
     * Return server error response
     *
     * @param string $message
     * @return JsonResponse
     */
    private function serverErrorResponse(string $message): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error_code' => 'SERVER_ERROR',
        ], 500);
    }
}
