<?php

namespace Shared\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Shared\Services\HybridDatabaseService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Exception;

class HybridTenantDatabaseMiddleware
{
    public function __construct(
        private HybridDatabaseService $hybridDatabaseService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            // Get tenant identifier from headers or request
            $tenantId = $this->getTenantIdentifier($request);
            
            if (!$tenantId) {
                return $this->errorResponse('Tenant identifier is required', 400);
            }

            // Validate tenant exists and is active
            $tenant = $this->hybridDatabaseService->getTenant($tenantId);
            
            if (!$tenant) {
                return $this->errorResponse('Tenant not found', 404);
            }

            if (!$tenant['is_active']) {
                return $this->errorResponse('Tenant is inactive', 403);
            }

            // Get current service
            $currentService = $this->hybridDatabaseService->getCurrentService();
            
            // CRITICAL: Use tenant['id'] instead of tenantId for consistency
            $actualTenantId = $tenant['id'];
            
            // Extract domain prefix (e.g., "acme" from "acme.hrms.local")
            $domain = $tenant['domain'];
            $domainPrefix = explode('.', $domain)[0];
            
            // Check if tenant service database exists using correct naming convention
            $databaseName = "hrms_tenant_{$domainPrefix}";
            if (!$this->tenantServiceDatabaseExists($databaseName)) {
                return $this->errorResponse("Tenant {$currentService} database not found", 404);
            }

            // Switch to tenant service database using actual tenant ID
            $this->hybridDatabaseService->switchToTenantDatabase($actualTenantId);

            // CRITICAL: Verify the connection switch was successful
            $connectionInfo = $this->hybridDatabaseService->getCurrentConnectionInfo();
            if (isset($connectionInfo['error']) || $connectionInfo['database_name'] !== $databaseName) {
                Log::error('Database connection switch verification failed', [
                    'expected_database' => $databaseName,
                    'actual_connection' => $connectionInfo,
                ]);
                return $this->errorResponse('Database connection failed', 500);
            }

            // Add tenant and service context to request
            $request->merge([
                'tenant_id' => $tenant['id'],
                'tenant_domain' => $tenant['domain'],
                'tenant_name' => $tenant['name'],
                'service_name' => $currentService,
                'database_name' => $databaseName,
            ]);

            // Add tenant to request attributes for easy access
            $request->attributes->set('tenant', $tenant);
            $request->attributes->set('service', $currentService);

            $response = $next($request);

            // CRITICAL: Always switch back to central database after request
            try {
                $this->hybridDatabaseService->switchToCentralDatabase();
            } catch (Exception $e) {
                Log::error('Failed to switch back to central database', [
                    'error' => $e->getMessage(),
                    'tenant_id' => $actualTenantId,
                ]);
                // Don't fail the request, but log the error
            }

            return $response;

        } catch (Exception $e) {
            Log::error('Hybrid tenant database middleware error', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId ?? 'unknown',
                'service' => $this->hybridDatabaseService->getCurrentService(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse('Database service error', 500);
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

        // Try X-Tenant-Domain header
        $tenantDomain = $request->header('X-Tenant-Domain');
        if ($tenantDomain) {
            // Use the full domain for tenant lookup
            return $tenantDomain;
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
            $result = DB::select("SELECT 1 FROM pg_database WHERE datname = ?", [$databaseName]);
            return !empty($result);
        } catch (Exception $e) {
            return false;
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
