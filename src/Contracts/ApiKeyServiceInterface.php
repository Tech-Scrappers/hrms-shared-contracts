<?php

namespace Shared\Contracts;

/**
 * API Key Service Interface
 * 
 * Defines the contract for API key validation and management.
 * Implementations can use HTTP API, local database, or mock data.
 * 
 * This interface enables:
 * - Dependency Injection
 * - Easy mocking for tests
 * - Implementation swapping
 * - SOLID principles adherence
 */
interface ApiKeyServiceInterface
{
    /**
     * Validate an API key
     *
     * @param string $apiKey The API key to validate
     * @return array|null API key data if valid, null otherwise
     */
    public function validateApiKey(string $apiKey): ?array;

    /**
     * Get API key by ID
     *
     * @param string $apiKeyId API key UUID
     * @return array|null API key data or null if not found
     */
    public function getApiKey(string $apiKeyId): ?array;

    /**
     * Get all API keys for a tenant
     *
     * @param string $tenantId Tenant UUID
     * @return array Array of API keys
     */
    public function getTenantApiKeys(string $tenantId): array;
}

