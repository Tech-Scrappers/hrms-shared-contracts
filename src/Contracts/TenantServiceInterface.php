<?php

namespace Shared\Contracts;

/**
 * Tenant Service Interface
 * 
 * Defines the contract for tenant information retrieval.
 * Implementations can use HTTP API, cache, or mock data.
 * 
 * This interface enables:
 * - Dependency Injection
 * - Easy mocking for tests
 * - Implementation swapping
 * - SOLID principles adherence
 */
interface TenantServiceInterface
{
    /**
     * Get tenant information by tenant ID
     *
     * @param string $tenantId Tenant UUID
     * @return array|null Tenant data or null if not found
     */
    public function getTenant(string $tenantId): ?array;

    /**
     * Get tenant information by domain name
     *
     * @param string $domain Tenant domain
     * @return array|null Tenant data or null if not found
     */
    public function getTenantByDomain(string $domain): ?array;

    /**
     * Check if a tenant is active
     *
     * @param string $tenantId Tenant UUID
     * @return bool True if tenant exists and is active
     */
    public function isTenantActive(string $tenantId): bool;

    /**
     * Clear tenant cache
     *
     * @param string $tenantId Tenant UUID
     * @return void
     */
    public function clearTenantCache(string $tenantId): void;

    /**
     * Get circuit breaker status for monitoring
     *
     * @return array Circuit breaker status information
     */
    public function getCircuitBreakerStatus(): array;
}

