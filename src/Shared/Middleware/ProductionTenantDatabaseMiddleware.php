<?php

namespace Shared\Middleware;

use Closure;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Shared\Services\DatabaseConnectionManager;
use Shared\Services\HybridDatabaseService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Production-ready tenant database middleware
 * Handles connection switching with proper cleanup and error handling
 */
class ProductionTenantDatabaseMiddleware
{
    public function __construct(
        private HybridDatabaseService $hybridDatabaseService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = null;
        $connectionSwitched = false;

        try {
            // Get tenant identifier from request
            $tenantId = $this->getTenantIdentifier($request);

            if (! $tenantId) {
                return $this->errorResponse('Tenant identifier is required', 400);
            }

            // Validate tenant exists and is active
            $tenant = $this->hybridDatabaseService->getTenant($tenantId);

            if (! $tenant) {
                return $this->errorResponse('Tenant not found', 404);
            }

            if (! $tenant['is_active']) {
                return $this->errorResponse('Tenant is inactive', 403);
            }

            // Get current service
            $currentService = $this->hybridDatabaseService->getCurrentService();
            $actualTenantId = $tenant['id'];

            // Check if tenant service database exists
            $databaseName = "tenant_{$actualTenantId}_{$currentService}";
            if (! $this->tenantServiceDatabaseExists($databaseName)) {
                return $this->errorResponse("Tenant {$currentService} database not found", 404);
            }

            // Switch to tenant service database using production-ready connection manager
            DatabaseConnectionManager::switchToTenantDatabase($actualTenantId, $currentService);
            $connectionSwitched = true;

            // Verify the connection switch was successful
            $connectionInfo = DatabaseConnectionManager::getCurrentConnectionInfo();
            if (isset($connectionInfo['error']) || $connectionInfo['database_name'] !== $databaseName) {
                Log::error('Database connection switch verification failed', [
                    'expected_database' => $databaseName,
                    'actual_connection' => $connectionInfo,
                ]);

                return $this->errorResponse('Database connection failed', 500);
            }

            // Add tenant and service context to request
            $this->addTenantContextToRequest($request, $tenant, $currentService, $databaseName);

            // Process the request
            $response = $next($request);

            return $response;

        } catch (Exception $e) {
            Log::error('Production tenant database middleware error', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId ?? 'unknown',
                'service' => $this->hybridDatabaseService->getCurrentService(),
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
     * Get tenant identifier from request
     */
    private function getTenantIdentifier(Request $request): ?string
    {
        // Try HRMS-Client-ID header first
        $tenantId = $request->header('HRMS-Client-ID');
        if ($tenantId) {
            return $tenantId;
        }

        // Try tenant_id from request data
        $tenantId = $request->get('tenant_id');
        if ($tenantId) {
            return $tenantId;
        }

        // Try subdomain
        $host = $request->getHost();
        $subdomain = explode('.', $host)[0];
        if ($subdomain && $subdomain !== 'www' && $subdomain !== 'api') {
            return $subdomain;
        }

        return null;
    }

    /**
     * Check if tenant service database exists
     */
    private function tenantServiceDatabaseExists(string $databaseName): bool
    {
        try {
            $result = \DB::select('SELECT 1 FROM pg_database WHERE datname = ?', [$databaseName]);

            return ! empty($result);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Add tenant context to request
     */
    private function addTenantContextToRequest(Request $request, array $tenant, string $service, string $databaseName): void
    {
        // Add to request data
        $request->merge([
            'tenant_id' => $tenant['id'],
            'tenant_domain' => $tenant['domain'],
            'tenant_name' => $tenant['name'],
            'service_name' => $service,
            'database_name' => $databaseName,
        ]);

        // Add to request attributes for easy access
        $request->attributes->set('tenant', $tenant);
        $request->attributes->set('service', $service);
        $request->attributes->set('database_name', $databaseName);
    }

    /**
     * Cleanup database connections
     */
    private function cleanupConnections(string $tenantId): void
    {
        try {
            // Switch back to central database using connection manager
            DatabaseConnectionManager::switchToCentralDatabase();

            // Cleanup old connections from pool
            DatabaseConnectionManager::cleanupOldConnections(30); // 30 minutes

            Log::debug('Database connections cleaned up', [
                'tenant_id' => $tenantId,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to cleanup database connections', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            // Don't throw here as this is cleanup
        }
    }

    /**
     * Return error response
     */
    private function errorResponse(string $message, int $statusCode): Response
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error_code' => 'TENANT_DATABASE_ERROR',
            'service' => $this->hybridDatabaseService->getCurrentService(),
        ], $statusCode);
    }
}
