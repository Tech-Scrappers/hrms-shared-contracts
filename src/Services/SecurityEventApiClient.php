<?php

namespace Shared\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Shared\Contracts\SecurityEventServiceInterface;

/**
 * Security Event API Client
 * 
 * Provides HTTP-based access to security event logging in Identity Service.
 * Implements circuit breaker pattern and async logging for resilience.
 * 
 * Best Practices:
 * - Service-to-service communication via HTTP API (not direct DB access)
 * - Async/fire-and-forget for non-critical logging
 * - Local fallback logging when Identity Service is unavailable
 * - Circuit breaker to prevent cascading failures
 * - Comprehensive error handling
 */
class SecurityEventApiClient implements SecurityEventServiceInterface
{
    private const REQUEST_TIMEOUT = 5; // seconds
    private const CIRCUIT_BREAKER_THRESHOLD = 5;
    private const CIRCUIT_BREAKER_TIMEOUT = 60; // seconds
    
    private const CIRCUIT_BREAKER_KEY = 'circuit_breaker_identity_service_security';
    private const FAILURE_COUNT_KEY = 'circuit_breaker_identity_service_security_failures';

    /**
     * Log a security event to Identity Service
     * 
     * This method is fire-and-forget - it won't throw exceptions
     * to avoid disrupting the main application flow.
     *
     * @param array $eventData Security event data
     * @return bool True if logged successfully, false otherwise
     */
    public function logSecurityEvent(array $eventData): bool
    {
        // Check circuit breaker status
        if ($this->isCircuitOpen()) {
            Log::warning('Circuit breaker is open, logging security event locally only', [
                'event_type' => $eventData['event_type'] ?? 'unknown',
            ]);
            $this->logLocally($eventData);
            return false;
        }
        
        try {
            $identityServiceUrl = config('services.identity_service.url');
            
            if (!$identityServiceUrl) {
                Log::error('Identity Service URL not configured for security event logging');
                $this->logLocally($eventData);
                return false;
            }
            
            // Add timestamp if not present
            if (!isset($eventData['timestamp'])) {
                $eventData['timestamp'] = now()->toISOString();
            }
            
            // Add request context if not present
            if (!isset($eventData['request_id'])) {
                $eventData['request_id'] = request()->header('X-Request-ID') ?: \Illuminate\Support\Str::uuid()->toString();
            }
            
            $response = Http::timeout(self::REQUEST_TIMEOUT)
                ->withHeaders([
                    'X-Internal-Service-Secret' => config('services.internal_secret'),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->post("{$identityServiceUrl}/api/v1/internal/security-events", $eventData);
            
            if ($response->successful()) {
                $this->resetCircuitBreaker();
                
                Log::debug('Security event logged successfully via API', [
                    'event_type' => $eventData['event_type'] ?? 'unknown',
                    'status' => $response->status(),
                ]);
                
                return true;
            }
            
            Log::warning('Failed to log security event to Identity Service', [
                'event_type' => $eventData['event_type'] ?? 'unknown',
                'status' => $response->status(),
                'response' => $response->body(),
            ]);
            
            $this->recordFailure();
            $this->logLocally($eventData);
            return false;
            
        } catch (\Exception $e) {
            Log::error('Exception while logging security event to Identity Service', [
                'event_type' => $eventData['event_type'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
            
            $this->recordFailure();
            $this->logLocally($eventData);
            return false;
        }
    }

    /**
     * Get security events for a tenant
     *
     * @param string $tenantId Tenant UUID
     * @param array $filters Optional filters (event_type, limit, offset, from_date, to_date)
     * @return array Array of security events
     */
    public function getSecurityEvents(string $tenantId, array $filters = []): array
    {
        // Check circuit breaker status
        if ($this->isCircuitOpen()) {
            Log::warning('Circuit breaker is open, cannot fetch security events', [
                'tenant_id' => $tenantId,
            ]);
            return [];
        }
        
        try {
            $identityServiceUrl = config('services.identity_service.url');
            
            if (!$identityServiceUrl) {
                Log::error('Identity Service URL not configured');
                return [];
            }
            
            $queryParams = array_merge([
                'tenant_id' => $tenantId,
            ], $filters);
            
            $response = Http::timeout(self::REQUEST_TIMEOUT)
                ->withHeaders([
                    'X-Internal-Service-Secret' => config('services.internal_secret'),
                    'Accept' => 'application/json',
                ])
                ->get("{$identityServiceUrl}/api/v1/internal/security-events", $queryParams);
            
            if ($response->successful()) {
                $this->resetCircuitBreaker();
                
                Log::debug('Security events fetched successfully via API', [
                    'tenant_id' => $tenantId,
                    'count' => count($response->json('data', [])),
                ]);
                
                return $response->json('data', []);
            }
            
            Log::warning('Failed to fetch security events from Identity Service', [
                'tenant_id' => $tenantId,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);
            
            $this->recordFailure();
            return [];
            
        } catch (\Exception $e) {
            Log::error('Exception while fetching security events from Identity Service', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            
            $this->recordFailure();
            return [];
        }
    }

    /**
     * Get security statistics for a tenant
     *
     * @param string $tenantId Tenant UUID
     * @param int $days Number of days to look back
     * @return array Security statistics
     */
    public function getSecurityStatistics(string $tenantId, int $days = 30): array
    {
        // Check circuit breaker status
        if ($this->isCircuitOpen()) {
            Log::warning('Circuit breaker is open, cannot fetch security statistics', [
                'tenant_id' => $tenantId,
            ]);
            return $this->getEmptyStatistics();
        }
        
        try {
            $identityServiceUrl = config('services.identity_service.url');
            
            if (!$identityServiceUrl) {
                Log::error('Identity Service URL not configured');
                return $this->getEmptyStatistics();
            }
            
            $response = Http::timeout(self::REQUEST_TIMEOUT)
                ->withHeaders([
                    'X-Internal-Service-Secret' => config('services.internal_secret'),
                    'Accept' => 'application/json',
                ])
                ->get("{$identityServiceUrl}/api/v1/internal/security-events/statistics", [
                    'tenant_id' => $tenantId,
                    'days' => $days,
                ]);
            
            if ($response->successful()) {
                $this->resetCircuitBreaker();
                
                Log::debug('Security statistics fetched successfully via API', [
                    'tenant_id' => $tenantId,
                    'days' => $days,
                ]);
                
                return $response->json('data', $this->getEmptyStatistics());
            }
            
            Log::warning('Failed to fetch security statistics from Identity Service', [
                'tenant_id' => $tenantId,
                'status' => $response->status(),
            ]);
            
            $this->recordFailure();
            return $this->getEmptyStatistics();
            
        } catch (\Exception $e) {
            Log::error('Exception while fetching security statistics from Identity Service', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            
            $this->recordFailure();
            return $this->getEmptyStatistics();
        }
    }

    /**
     * Check for suspicious patterns for a tenant
     *
     * @param string $tenantId Tenant UUID
     * @param int $hours Number of hours to analyze
     * @return array Array of suspicious patterns found
     */
    public function checkSuspiciousPatterns(string $tenantId, int $hours = 24): array
    {
        // Check circuit breaker status
        if ($this->isCircuitOpen()) {
            Log::warning('Circuit breaker is open, cannot check suspicious patterns', [
                'tenant_id' => $tenantId,
            ]);
            return [];
        }
        
        try {
            $identityServiceUrl = config('services.identity_service.url');
            
            if (!$identityServiceUrl) {
                Log::error('Identity Service URL not configured');
                return [];
            }
            
            $response = Http::timeout(self::REQUEST_TIMEOUT)
                ->withHeaders([
                    'X-Internal-Service-Secret' => config('services.internal_secret'),
                    'Accept' => 'application/json',
                ])
                ->get("{$identityServiceUrl}/api/v1/internal/security-events/suspicious-patterns", [
                    'tenant_id' => $tenantId,
                    'hours' => $hours,
                ]);
            
            if ($response->successful()) {
                $this->resetCircuitBreaker();
                
                $patterns = $response->json('data', []);
                
                Log::debug('Suspicious patterns check completed via API', [
                    'tenant_id' => $tenantId,
                    'patterns_found' => count($patterns),
                ]);
                
                return $patterns;
            }
            
            Log::warning('Failed to check suspicious patterns from Identity Service', [
                'tenant_id' => $tenantId,
                'status' => $response->status(),
            ]);
            
            $this->recordFailure();
            return [];
            
        } catch (\Exception $e) {
            Log::error('Exception while checking suspicious patterns from Identity Service', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            
            $this->recordFailure();
            return [];
        }
    }

    /**
     * Log security event locally as fallback
     *
     * @param array $eventData Security event data
     * @return void
     */
    private function logLocally(array $eventData): void
    {
        // Log to Laravel's security channel
        Log::channel('security')->warning('Security Event (Logged Locally)', $eventData);
        
        // Optionally store in local database table for later sync
        // This allows events to be retried when Identity Service recovers
        $this->storeForRetry($eventData);
    }

    /**
     * Store security event for later retry
     *
     * @param array $eventData Security event data
     * @return void
     */
    private function storeForRetry(array $eventData): void
    {
        try {
            // Check if failed_security_events table exists (optional feature)
            if (!\Illuminate\Support\Facades\Schema::hasTable('failed_security_events')) {
                return;
            }
            
            DB::table('failed_security_events')->insert([
                'event_data' => json_encode($eventData),
                'failed_at' => now(),
                'retry_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            
        } catch (\Exception $e) {
            // Silently fail - don't disrupt main flow
            Log::debug('Could not store security event for retry', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get empty statistics structure
     *
     * @return array Empty statistics
     */
    private function getEmptyStatistics(): array
    {
        return [
            'total_events' => 0,
            'cross_tenant_attempts' => 0,
            'permission_denied' => 0,
            'suspicious_activity' => 0,
            'api_key_events' => 0,
            'period_days' => 0,
        ];
    }

    /**
     * Check if circuit breaker is open
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
            
            Log::error('Circuit breaker opened for Identity Service (security API)', [
                'failures' => $failures,
                'threshold' => self::CIRCUIT_BREAKER_THRESHOLD,
                'timeout' => self::CIRCUIT_BREAKER_TIMEOUT,
            ]);
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
            
            Log::info('Circuit breaker reset for Identity Service (security API)');
        }
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

