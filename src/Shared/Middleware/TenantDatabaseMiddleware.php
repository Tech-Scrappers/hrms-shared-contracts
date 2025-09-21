<?php

namespace Shared\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Shared\Services\TenantDatabaseService;
use Illuminate\Support\Facades\Log;
use Exception;

class TenantDatabaseMiddleware
{
    public function __construct(
        private TenantDatabaseService $tenantDatabaseService
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
            $tenant = $this->tenantDatabaseService->getTenant($tenantId);
            
            if (!$tenant) {
                return $this->errorResponse('Tenant not found', 404);
            }

            if (!$tenant['is_active']) {
                return $this->errorResponse('Tenant is inactive', 403);
            }

            // Check if tenant database exists
            if (!$this->tenantDatabaseService->tenantDatabaseExists($tenant['database_name'])) {
                return $this->errorResponse('Tenant database not found', 404);
            }

            // Switch to tenant database
            $this->tenantDatabaseService->switchToTenantDatabase($tenant['id']);

            // Add tenant context to request
            $request->merge([
                'tenant_id' => $tenant['id'],
                'tenant_domain' => $tenant['domain'],
                'tenant_name' => $tenant['name'],
            ]);

            // Add tenant to request attributes for easy access
            $request->attributes->set('tenant', $tenant);

            $response = $next($request);

            // Switch back to central database after request
            $this->tenantDatabaseService->switchToCentralDatabase();

            return $response;

        } catch (Exception $e) {
            Log::error('Tenant database middleware error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Ensure we switch back to central database on error
            $this->tenantDatabaseService->switchToCentralDatabase();

            return $this->errorResponse('Internal server error', 500);
        }
    }

    /**
     * Get tenant identifier from request
     *
     * @param Request $request
     * @return string|null
     */
    private function getTenantIdentifier(Request $request): ?string
    {
        // Try different header names
        $tenantId = $request->header('HRMS-Client-ID') 
                 ?? $request->header('X-Tenant-Domain')
                 ?? $request->header('Tenant-ID')
                 ?? $request->header('Tenant-Domain');

        // Try query parameter
        if (!$tenantId) {
            $tenantId = $request->query('tenant_id') 
                     ?? $request->query('tenant_domain');
        }

        // Try request body
        if (!$tenantId) {
            $tenantId = $request->input('tenant_id') 
                     ?? $request->input('tenant_domain');
        }

        return $tenantId;
    }

    /**
     * Create error response
     *
     * @param string $message
     * @param int $statusCode
     * @return Response
     */
    private function errorResponse(string $message, int $statusCode): Response
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'code' => $statusCode,
            'timestamp' => now()->toISOString(),
        ], $statusCode);
    }
}
