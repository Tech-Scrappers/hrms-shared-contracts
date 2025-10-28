<?php

namespace Shared\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Shared\Contracts\TenantServiceInterface;

/**
 * Tenant API Client
 * 
 * Provides HTTP-based access to tenant information from Identity Service.
 * Implements circuit breaker pattern for resilience and caching for performance.
 * 
 * Best Practices:
 * - Service-to-service communication via HTTP API (not direct DB access)
 * - Circuit breaker to prevent cascading failures
 * - Caching for performance optimization
 * - Fallback mechanisms for resilience
 * - Comprehensive logging and monitoring
 */
class TenantApiClient implements TenantServiceInterface
{
    private const CACHE_TTL = 300; // 5 minutes
    private const CACHE_FALLBACK_TTL = 3600; // 1 hour for fallback data
    private const CIRCUIT_BREAKER_THRESHOLD = 5;
    private const CIRCUIT_BREAKER_TIMEOUT = 60; // seconds
    private const REQUEST_TIMEOUT = 5; // seconds
    
    private const CIRCUIT_BREAKER_KEY = 'circuit_breaker_identity_service_tenant';
    private const FAILURE_COUNT_KEY = 'circuit_breaker_identity_service_tenant_failures';

    /**
     * Get tenant information by tenant ID
     *
     * @param string $tenantId The tenant UUID
     * @return array|null Tenant data or null if not found
     */
    public function getTenant(string $tenantId): ?array
    {
        $cacheKey = "tenant_info_{$tenantId}";
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($tenantId) {
            // Check circuit breaker status
            if ($this->isCircuitOpen()) {
                Log::warning('Circuit breaker is open, using fallback data', [
                    'tenant_id' => $tenantId,
                    'service' => 'identity',
                ]);
                return $this->getFallback($tenantId);
            }
            
            try {
                $identityServiceUrl = config('services.identity_service.url');
                
                if (!$identityServiceUrl) {
                    Log::error('Identity Service URL not configured');
                    return $this->getFallback($tenantId);
                }
                
                $response = Http::timeout(self::REQUEST_TIMEOUT)
                    ->withHeaders([
                        'X-Internal-Service-Secret' => config('services.internal_secret'),
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ])
                    ->get("{$identityServiceUrl}/api/v1/internal/tenants/{$tenantId}");
                
                if ($response->successful()) {
                    $this->resetCircuitBreaker();
                    $data = $response->json('data');
                    
                    // Cache fallback data for longer period
                    Cache::put("tenant_fallback_{$tenantId}", $data, self::CACHE_FALLBACK_TTL);
                    
                    Log::debug('Tenant fetched successfully via API', [
                        'tenant_id' => $tenantId,
                        'status' => $response->status(),
                    ]);
                    
                    return $data;
                }
                
                Log::warning('Failed to fetch tenant from Identity Service', [
                    'tenant_id' => $tenantId,
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);
                
                $this->recordFailure();
                return $this->getFallback($tenantId);
                
            } catch (\Exception $e) {
                Log::error('Exception while fetching tenant from Identity Service', [
                    'tenant_id' => $tenantId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                
                $this->recordFailure();
                return $this->getFallback($tenantId);
            }
        });
    }

    /**
     * Get tenant information by domain name
     *
     * @param string $domain The tenant domain
     * @return array|null Tenant data or null if not found
     */
    public function getTenantByDomain(string $domain): ?array
    {
        $cacheKey = "tenant_domain_{$domain}";
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($domain) {
            // Check circuit breaker status
            if ($this->isCircuitOpen()) {
                Log::warning('Circuit breaker is open, using fallback data', [
                    'domain' => $domain,
                    'service' => 'identity',
                ]);
                return $this->getFallbackByDomain($domain);
            }
            
            try {
                $identityServiceUrl = config('services.identity_service.url');
                
                if (!$identityServiceUrl) {
                    Log::error('Identity Service URL not configured');
                    return $this->getFallbackByDomain($domain);
                }
                
                $response = Http::timeout(self::REQUEST_TIMEOUT)
                    ->withHeaders([
                        'X-Internal-Service-Secret' => config('services.internal_secret'),
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ])
                    ->get("{$identityServiceUrl}/api/v1/internal/tenants/domain/{$domain}");
                
                if ($response->successful()) {
                    $this->resetCircuitBreaker();
                    $data = $response->json('data');
                    
                    // Cache fallback data for longer period
                    Cache::put("tenant_fallback_domain_{$domain}", $data, self::CACHE_FALLBACK_TTL);
                    
                    // Also cache by ID for faster subsequent lookups
                    if (isset($data['id'])) {
                        Cache::put("tenant_fallback_{$data['id']}", $data, self::CACHE_FALLBACK_TTL);
                    }
                    
                    Log::debug('Tenant fetched successfully via API by domain', [
                        'domain' => $domain,
                        'status' => $response->status(),
                    ]);
                    
                    return $data;
                }
                
                Log::warning('Failed to fetch tenant by domain from Identity Service', [
                    'domain' => $domain,
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);
                
                $this->recordFailure();
                return $this->getFallbackByDomain($domain);
                
            } catch (\Exception $e) {
                Log::error('Exception while fetching tenant by domain from Identity Service', [
                    'domain' => $domain,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                
                $this->recordFailure();
                return $this->getFallbackByDomain($domain);
            }
        });
    }

    /**
     * Check if a tenant is active
     *
     * @param string $tenantId The tenant UUID
     * @return bool True if tenant exists and is active
     */
    public function isTenantActive(string $tenantId): bool
    {
        $tenant = $this->getTenant($tenantId);
        
        return $tenant && isset($tenant['is_active']) && $tenant['is_active'] === true;
    }

    /**
     * Clear tenant cache (useful after tenant updates)
     *
     * @param string $tenantId The tenant UUID
     * @return void
     */
    public function clearTenantCache(string $tenantId): void
    {
        $tenant = $this->getTenant($tenantId);
        
        // Clear all related cache keys
        Cache::forget("tenant_info_{$tenantId}");
        Cache::forget("tenant_fallback_{$tenantId}");
        
        if ($tenant && isset($tenant['domain'])) {
            Cache::forget("tenant_domain_{$tenant['domain']}");
            Cache::forget("tenant_fallback_domain_{$tenant['domain']}");
        }
        
        Log::info('Tenant cache cleared', ['tenant_id' => $tenantId]);
    }

    /**
     * Check if circuit breaker is open (service is failing)
     *
     * @return bool True if circuit is open
     */
    private function isCircuitOpen(): bool
    {
        return Cache::has(self::CIRCUIT_BREAKER_KEY);
    }

    /**
     * Record a failure and potentially open the circuit breaker
     *
     * @return void
     */
    private function recordFailure(): void
    {
        $failures = Cache::get(self::FAILURE_COUNT_KEY, 0);
        $failures++;
        
        Cache::put(self::FAILURE_COUNT_KEY, $failures, self::CIRCUIT_BREAKER_TIMEOUT);
        
        if ($failures >= self::CIRCUIT_BREAKER_THRESHOLD) {
            Cache::put(self::CIRCUIT_BREAKER_KEY, true, self::CIRCUIT_BREAKER_TIMEOUT);
            
            Log::error('Circuit breaker opened for Identity Service (tenant API)', [
                'failures' => $failures,
                'threshold' => self::CIRCUIT_BREAKER_THRESHOLD,
                'timeout' => self::CIRCUIT_BREAKER_TIMEOUT,
            ]);
            
            // Notify monitoring/alerting system
            $this->notifyCircuitBreakerOpened($failures);
        }
    }

    /**
     * Reset circuit breaker after successful request
     *
     * @return void
     */
    private function resetCircuitBreaker(): void
    {
        if (Cache::has(self::FAILURE_COUNT_KEY) || Cache::has(self::CIRCUIT_BREAKER_KEY)) {
            Cache::forget(self::FAILURE_COUNT_KEY);
            Cache::forget(self::CIRCUIT_BREAKER_KEY);
            
            Log::info('Circuit breaker reset for Identity Service (tenant API)');
        }
    }

    /**
     * Get fallback tenant data from cache
     *
     * @param string $tenantId The tenant UUID
     * @return array|null Cached tenant data or null
     */
    private function getFallback(string $tenantId): ?array
    {
        $fallback = Cache::get("tenant_fallback_{$tenantId}");
        
        if ($fallback) {
            Log::info('Using fallback tenant data from cache', [
                'tenant_id' => $tenantId,
            ]);
        } else {
            Log::warning('No fallback tenant data available', [
                'tenant_id' => $tenantId,
            ]);
        }
        
        return $fallback;
    }

    /**
     * Get fallback tenant data by domain from cache
     *
     * @param string $domain The tenant domain
     * @return array|null Cached tenant data or null
     */
    private function getFallbackByDomain(string $domain): ?array
    {
        $fallback = Cache::get("tenant_fallback_domain_{$domain}");
        
        if ($fallback) {
            Log::info('Using fallback tenant data from cache (by domain)', [
                'domain' => $domain,
            ]);
        } else {
            Log::warning('No fallback tenant data available (by domain)', [
                'domain' => $domain,
            ]);
        }
        
        return $fallback;
    }

    /**
     * Notify monitoring system that circuit breaker opened
     * 
     * This could integrate with services like PagerDuty, Slack, etc.
     *
     * @param int $failures Number of failures that triggered the circuit breaker
     * @return void
     */
    private function notifyCircuitBreakerOpened(int $failures): void
    {
        // TODO: Integrate with your monitoring/alerting system
        // Examples:
        // - Send Slack notification
        // - Trigger PagerDuty alert
        // - Send email to ops team
        // - Push metric to CloudWatch/Datadog
        
        Log::critical('ALERT: Identity Service circuit breaker opened', [
            'service' => 'identity',
            'component' => 'tenant-api',
            'failures' => $failures,
            'threshold' => self::CIRCUIT_BREAKER_THRESHOLD,
            'action_required' => 'Check Identity Service health',
        ]);
    }

    /**
     * Get circuit breaker status (for monitoring/debugging)
     *
     * @return array Circuit breaker status information
     */
    public function getCircuitBreakerStatus(): array
    {
        return [
            'is_open' => $this->isCircuitOpen(),
            'failure_count' => Cache::get(self::FAILURE_COUNT_KEY, 0),
            'threshold' => self::CIRCUIT_BREAKER_THRESHOLD,
            'timeout' => self::CIRCUIT_BREAKER_TIMEOUT,
        ];
    }
}

