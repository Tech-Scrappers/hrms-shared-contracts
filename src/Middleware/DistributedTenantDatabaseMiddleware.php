<?php

namespace Shared\Middleware;

use Closure;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Shared\Services\DistributedDatabaseService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Distributed Tenant Database Middleware
 * 
 * Handles tenant database switching in a fully distributed microservices
 * architecture where each service has its own PostgreSQL instance.
 * 
 * Key Features:
 * - Docker-aware connection management
 * - Automatic connection cleanup
 * - Proper error handling with rollback
 * - Request context enrichment with tenant data
 * - Production-ready with comprehensive logging
 */
class DistributedTenantDatabaseMiddleware
{
    public function __construct(
        private DistributedDatabaseService $distributedDatabaseService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = null;
        $connectionSwitched = false;

        try {
            // Step 1: Extract tenant identifier from request
            $tenantId = $this->getTenantIdentifier($request);

            if (!$tenantId) {
                return $this->errorResponse('Tenant identifier is required', 400);
            }

            // Step 2: Validate tenant exists and is active
            $tenant = $this->distributedDatabaseService->getTenant($tenantId);

            if (!$tenant) {
                Log::warning('Tenant not found', [
                    'tenant_identifier' => $tenantId,
                    'service' => $this->distributedDatabaseService->getCurrentService(),
                ]);

                return $this->errorResponse('Tenant not found', 404);
            }

            if (!($tenant['is_active'] ?? true)) {
                Log::warning('Tenant is inactive', [
                    'tenant_id' => $tenant['id'],
                    'tenant_name' => $tenant['name'],
                    'service' => $this->distributedDatabaseService->getCurrentService(),
                ]);

                return $this->errorResponse('Tenant is inactive', 403);
            }

            // Step 3: Get current service context
            $currentService = $this->distributedDatabaseService->getCurrentService();
            $actualTenantId = $tenant['id'];
            // PostgreSQL supports hyphens in database names, no need to sanitize
            // Keep the tenant ID as-is for consistency with existing databases
            $databaseName = "tenant_{$actualTenantId}_{$currentService}";

            // Step 4: Verify tenant database exists on current service's instance
            if (!$this->distributedDatabaseService->tenantDatabaseExists($databaseName)) {
                Log::warning('Tenant database not found on service instance', [
                    'tenant_id' => $actualTenantId,
                    'service' => $currentService,
                    'database_name' => $databaseName,
                ]);

                return $this->errorResponse(
                    "Tenant database not found on {$currentService} service",
                    404
                );
            }

            // Step 5: Switch to tenant database on current service's DB instance
            $this->distributedDatabaseService->switchToTenantDatabase($actualTenantId);
            $connectionSwitched = true;

            // Step 6: Verify connection switch was successful
            $connectionInfo = $this->distributedDatabaseService->getCurrentConnectionInfo();
            
            if (isset($connectionInfo['error'])) {
                Log::error('Database connection failed after switch', [
                    'tenant_id' => $actualTenantId,
                    'service' => $currentService,
                    'connection_info' => $connectionInfo,
                ]);

                return $this->errorResponse('Database connection failed', 500);
            }

            if ($connectionInfo['database_name'] !== $databaseName) {
                Log::error('Database connection switch verification failed', [
                    'tenant_id' => $actualTenantId,
                    'service' => $currentService,
                    'expected_database' => $databaseName,
                    'actual_database' => $connectionInfo['database_name'],
                ]);

                return $this->errorResponse('Database connection verification failed', 500);
            }

            // Step 7: Enrich request with tenant context
            $this->addTenantContextToRequest($request, $tenant, $currentService, $databaseName);

            // Step 8: Process the request
            $response = $next($request);

            // Step 9: Log successful request processing
            Log::debug('Request processed successfully on tenant database', [
                'tenant_id' => $actualTenantId,
                'service' => $currentService,
                'database_name' => $databaseName,
                'status_code' => $response->getStatusCode(),
            ]);

            return $response;

        } catch (Exception $e) {
            Log::error('Distributed tenant database middleware error', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId ?? 'unknown',
                'service' => $this->distributedDatabaseService->getCurrentService(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse('Database service error', 500);

        } finally {
            // CRITICAL: Always cleanup connections, even if an exception occurred
            if ($connectionSwitched) {
                $this->cleanupConnections($tenantId ?? 'unknown');
            }
        }
    }

    /**
     * Extract tenant identifier from request
     * 
     * Supports multiple methods of tenant identification:
     * - HRMS-Client-ID header
     * - X-Tenant-Domain header
     * - X-Tenant-ID header
     * - tenant_id query parameter
     * - tenant_id request body
     * - Subdomain extraction
     */
    private function getTenantIdentifier(Request $request): ?string
    {
        // Priority 1: HRMS-Client-ID header (recommended for API clients)
        $tenantId = $request->header('HRMS-Client-ID');
        if ($tenantId) {
            return $tenantId;
        }

        // Priority 2: X-Tenant-Domain header
        $tenantDomain = $request->header('X-Tenant-Domain');
        if ($tenantDomain) {
            return $tenantDomain;
        }

        // Priority 3: X-Tenant-ID header
        $tenantId = $request->header('X-Tenant-ID');
        if ($tenantId) {
            return $tenantId;
        }

        // Priority 4: Query parameter
        $tenantId = $request->query('tenant_id');
        if ($tenantId) {
            return $tenantId;
        }

        // Priority 5: Request body
        $tenantId = $request->input('tenant_id');
        if ($tenantId) {
            return $tenantId;
        }

        // Priority 6: Subdomain extraction (e.g., acme.hrms.local -> acme)
        $host = $request->getHost();
        $parts = explode('.', $host);
        
        if (count($parts) > 1) {
            $subdomain = $parts[0];
            
            // Filter out common non-tenant subdomains
            if (!in_array($subdomain, ['www', 'api', 'admin', 'app', 'localhost'])) {
                return $subdomain;
            }
        }

        return null;
    }

    /**
     * Add tenant context to request for use in controllers and services
     */
    private function addTenantContextToRequest(
        Request $request,
        array $tenant,
        string $service,
        string $databaseName
    ): void {
        // Add to request data (merge method makes it available via $request->get())
        $request->merge([
            'tenant_id' => $tenant['id'],
            'tenant_domain' => $tenant['domain'],
            'tenant_name' => $tenant['name'],
            'service_name' => $service,
            'database_name' => $databaseName,
        ]);

        // Add to request attributes (available via $request->attributes->get())
        $request->attributes->set('tenant', $tenant);
        $request->attributes->set('tenant_id', $tenant['id']);
        $request->attributes->set('service', $service);
        $request->attributes->set('database_name', $databaseName);

        Log::debug('Tenant context added to request', [
            'tenant_id' => $tenant['id'],
            'tenant_domain' => $tenant['domain'],
            'service' => $service,
            'database_name' => $databaseName,
        ]);
    }

    /**
     * Cleanup database connections after request processing
     */
    private function cleanupConnections(string $tenantId): void
    {
        try {
            // Switch back to central database
            $this->distributedDatabaseService->switchToCentralDatabase();

            // Cleanup old connections from pool (connections older than 30 minutes)
            $this->distributedDatabaseService->cleanupOldConnections(30);

            Log::debug('Database connections cleaned up successfully', [
                'tenant_id' => $tenantId,
                'service' => $this->distributedDatabaseService->getCurrentService(),
            ]);

        } catch (Exception $e) {
            // Log but don't throw - cleanup failures shouldn't break the response
            Log::error('Failed to cleanup database connections', [
                'tenant_id' => $tenantId,
                'service' => $this->distributedDatabaseService->getCurrentService(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create standardized error response
     */
    private function errorResponse(string $message, int $statusCode): Response
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error_code' => 'DISTRIBUTED_DATABASE_ERROR',
            'service' => $this->distributedDatabaseService->getCurrentService(),
            'timestamp' => now()->toISOString(),
        ], $statusCode);
    }
}

