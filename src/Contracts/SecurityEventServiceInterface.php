<?php

namespace Shared\Contracts;

/**
 * Security Event Service Interface
 * 
 * Defines the contract for security event logging and retrieval.
 * Implementations can use HTTP API, local storage, or mock data.
 * 
 * This interface enables:
 * - Dependency Injection
 * - Easy mocking for tests
 * - Implementation swapping
 * - SOLID principles adherence
 */
interface SecurityEventServiceInterface
{
    /**
     * Log a security event
     *
     * @param array $eventData Security event data
     * @return bool True if logged successfully
     */
    public function logSecurityEvent(array $eventData): bool;

    /**
     * Get security events for a tenant
     *
     * @param string $tenantId Tenant UUID
     * @param array $filters Optional filters
     * @return array Array of security events
     */
    public function getSecurityEvents(string $tenantId, array $filters = []): array;

    /**
     * Get security statistics for a tenant
     *
     * @param string $tenantId Tenant UUID
     * @param int $days Number of days to analyze
     * @return array Security statistics
     */
    public function getSecurityStatistics(string $tenantId, int $days = 30): array;

    /**
     * Check for suspicious patterns
     *
     * @param string $tenantId Tenant UUID
     * @param int $hours Number of hours to analyze
     * @return array Array of suspicious patterns found
     */
    public function checkSuspiciousPatterns(string $tenantId, int $hours = 24): array;

    /**
     * Get circuit breaker status for monitoring
     *
     * @return array Circuit breaker status information
     */
    public function getCircuitBreakerStatus(): array;
}

