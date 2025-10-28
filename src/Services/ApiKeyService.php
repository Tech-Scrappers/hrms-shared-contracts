<?php

namespace Shared\Services;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Shared\Contracts\ApiKeyServiceInterface;

/**
 * API Key Service - Microservices Implementation
 * 
 * This service validates API keys by calling the Identity service HTTP API.
 * It does NOT access the Identity database directly, maintaining proper
 * microservices separation of concerns and service autonomy.
 * 
 * Best Practices Implemented:
 * - Service-to-service communication via HTTP APIs only
 * - No cross-database dependencies
 * - Caching for performance
 * - Proper error handling and logging
 * - Circuit breaker pattern via retry logic
 * 
 * @package Shared\Services
 */
class ApiKeyService implements ApiKeyServiceInterface
{
    private const CACHE_PREFIX = 'api_key_';
    private const CACHE_TTL = 300; // 5 minutes

    private string $identityServiceUrl;

    public function __construct()
    {
        // Get Identity service URL from environment
        // Default assumes Docker network with service name 'identity-app'
        $this->identityServiceUrl = rtrim(
            env('IDENTITY_SERVICE_URL', 'http://identity-app:80'),
            '/'
        );
    }

    /**
     * Validate API key via Identity service HTTP API (microservices best practice)
     * 
     * This is the primary method for API key validation in the distributed architecture.
     * It calls the Identity service REST API instead of accessing its database directly,
     * maintaining service autonomy and proper separation of concerns.
     * 
     * @param string $apiKey The API key to validate
     * @return array|null API key data if valid, null otherwise
     */
    public function validateApiKey(string $apiKey): ?array
    {
        try {
            // Try cache first for performance
            $cacheKey = self::CACHE_PREFIX . hash('sha256', $apiKey);
            $cachedData = Cache::get($cacheKey);

            if ($cachedData) {
                Log::debug('API key validation: cache hit', [
                    'cache_key' => $cacheKey
                ]);
                return $cachedData;
            }
            
            // Call Identity service INTERNAL API
            Log::debug('API key validation: calling Identity service internal API', [
                'identity_url' => $this->identityServiceUrl,
                'api_key_preview' => substr($apiKey, 0, 10) . '...'
            ]);

            $response = Http::timeout(5)
                ->retry(2, 100) // Retry twice with 100ms delay (circuit breaker)
                ->withHeaders([
                    'X-Internal-Service-Secret' => config('services.internal_secret'),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->post("{$this->identityServiceUrl}/api/v1/internal/api-keys/validate", [
                    'api_key' => $apiKey
                ]);

            // Handle HTTP errors
            if (!$response->successful()) {
                Log::warning('API key validation failed: HTTP error', [
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
                return null;
            }

            $responseData = $response->json();

            // Validate response structure
            if (!isset($responseData['success']) || !$responseData['success']) {
                Log::warning('API key validation failed: unsuccessful response', [
                    'response' => $responseData
                ]);
                return null;
            }

            $result = $responseData['data'] ?? null;

            if (!$result) {
                Log::warning('API key validation failed: missing data in response', [
                    'response' => $responseData
                ]);
                return null;
            }

            // Cache the successful result
            Cache::put($cacheKey, $result, self::CACHE_TTL);

            Log::info('API key validated successfully', [
                'tenant_id' => $result['tenant_id'] ?? null,
                'api_key_name' => $result['name'] ?? null
            ]);

            return $result;

        } catch (Exception $e) {
            Log::error('API key validation exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Verify if an API key is valid (boolean check)
     * 
     * @param string $apiKey
     * @return bool
     */
    public function verifyApiKey(string $apiKey): bool
    {
        return $this->validateApiKey($apiKey) !== null;
    }

    /**
     * Get API key data
     * 
     * This is an alias for validateApiKey() for backward compatibility
     * 
     * @param string $apiKey
     * @return array|null
     */
    public function getApiKeyData(string $apiKey): ?array
    {
        return $this->validateApiKey($apiKey);
    }

    /**
     * Check if API key has a specific permission
     * 
     * Supports:
     * - Wildcard permissions (*)
     * - Exact match (employee.read)
     * - Pattern matching (employee.* matches employee.read, employee.write, etc.)
     * 
     * @param array $apiKeyData API key data from validateApiKey()
     * @param string $permission Permission to check
     * @return bool
     */
    public function hasPermission(array $apiKeyData, string $permission): bool
    {
        $permissions = $apiKeyData['permissions'] ?? [];

        // Wildcard permission grants everything
        if (in_array('*', $permissions)) {
            return true;
        }

        // Exact permission match
        if (in_array($permission, $permissions)) {
            return true;
        }

        // Pattern matching (e.g., 'employee.*' matches 'employee.read')
        foreach ($permissions as $perm) {
            if (str_ends_with($perm, '.*')) {
                $prefix = substr($perm, 0, -2);
                if (str_starts_with($permission, $prefix . '.')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get all permissions for an API key
     * 
     * @param string $apiKey
     * @return array
     */
    public function getPermissions(string $apiKey): array
    {
        $apiKeyData = $this->validateApiKey($apiKey);
        return $apiKeyData['permissions'] ?? [];
    }

    /**
     * Clear cached API key data
     * 
     * Call this after revoking or updating an API key
     * 
     * @param string $apiKey
     * @return void
     */
    public function clearCache(string $apiKey): void
    {
        $cacheKey = self::CACHE_PREFIX . hash('sha256', $apiKey);
        Cache::forget($cacheKey);

        Log::debug('API key cache cleared', [
            'cache_key' => $cacheKey
        ]);
    }

    /**
     * Clear all API key caches
     * 
     * Note: This is a best-effort operation. For production systems,
     * consider using cache tags or a dedicated cache store.
     * 
     * @return void
     */
    public function clearAllCaches(): void
    {
        Log::info('Clearing all API key caches - note: requires cache tags for complete clearing');
        // In production, implement using cache tags:
        // Cache::tags(['api_keys'])->flush();
    }

    /**
     * Generate a new API key string
     * 
     * Format: ak_{64_hex_characters}
     * Total length: 67 characters
     * 
     * @return string
     */
    public static function generateApiKey(): string
    {
        return 'ak_' . bin2hex(random_bytes(32));
    }

    /**
     * Hash an API key for storage
     * 
     * Note: This should only be used by the Identity service.
     * Other services should not store or hash API keys.
     * 
     * @param string $apiKey
     * @return string
     */
    public static function hashApiKey(string $apiKey): string
    {
        return hash('sha256', $apiKey);
    }

    /*
     * ============================================================================
     * REMOVED METHODS (Violations of Microservices Best Practices)
     * ============================================================================
     * 
     * The following methods were removed because they accessed the Identity
     * database directly from other services, violating service autonomy:
     * 
     * - updateLastUsed() - Should be handled by Identity service during validation
     * - revokeApiKey() - Should call Identity service API endpoint
     * - getApiKeyStats() - Should call Identity service API endpoint
     * - cleanupExpiredKeys() - Should be handled by Identity service internally
     * - clearApiKeyCache() - Replaced with clearCache()
     * 
     * If these operations are needed, they should be:
     * 1. Exposed as HTTP API endpoints in the Identity service
     * 2. Called via HTTP from other services
     * 3. Or handled automatically by the Identity service itself
     * 
     * CORRECT MICROSERVICES PATTERN:
     * 
     * ┌─────────────────────┐
     * │ Employee/Core       │
     * │ Service             │
     * │                     │
     * │ HTTP Request  ──────┼──────┐
     * └─────────────────────┘      │
     *                              ▼
     * ┌──────────────────────────────────────┐
     * │ Identity Service                      │
     * │ (Sole Authority for API Keys)         │
     * │                                       │
     * │ - Validates API keys                  │
     * │ - Updates last_used_at automatically  │
     * │ - Manages lifecycle (create/revoke)   │
     * │ - Provides statistics                 │
     * │ - Handles cleanup                     │
     * └──────────────────────────────────────┘
     */

    /**
     * Get API key by ID (from Identity Service)
     * 
     * Implementation of ApiKeyServiceInterface
     *
     * @param string $apiKeyId API key UUID
     * @return array|null API key data or null if not found
     */
    public function getApiKey(string $apiKeyId): ?array
    {
        try {
            $response = Http::timeout(5)
                ->withHeaders([
                    'X-Internal-Service-Secret' => config('services.internal_secret'),
                    'Accept' => 'application/json',
                ])
                ->get("{$this->identityServiceUrl}/api/v1/internal/api-keys/{$apiKeyId}");
            
            if (!$response->successful()) {
                Log::warning('Failed to get API key by ID', [
                    'api_key_id' => $apiKeyId,
                    'status' => $response->status(),
                ]);
                return null;
            }
            
            $responseData = $response->json();
            
            if (!isset($responseData['success']) || !$responseData['success']) {
                return null;
            }
            
            return $responseData['data'] ?? null;
            
        } catch (Exception $e) {
            Log::error('Exception getting API key by ID', [
                'api_key_id' => $apiKeyId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get all API keys for a tenant (from Identity Service)
     * 
     * Implementation of ApiKeyServiceInterface
     *
     * @param string $tenantId Tenant UUID
     * @return array Array of API keys
     */
    public function getTenantApiKeys(string $tenantId): array
    {
        try {
            $response = Http::timeout(5)
                ->withHeaders([
                    'X-Internal-Service-Secret' => config('services.internal_secret'),
                    'Accept' => 'application/json',
                ])
                ->get("{$this->identityServiceUrl}/api/v1/internal/api-keys/tenant/{$tenantId}");
            
            if (!$response->successful()) {
                Log::warning('Failed to get tenant API keys', [
                    'tenant_id' => $tenantId,
                    'status' => $response->status(),
                ]);
                return [];
            }
            
            $responseData = $response->json();
            
            if (!isset($responseData['success']) || !$responseData['success']) {
                return [];
            }
            
            return $responseData['data'] ?? [];
            
        } catch (Exception $e) {
            Log::error('Exception getting tenant API keys', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}
