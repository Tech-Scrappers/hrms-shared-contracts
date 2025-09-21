<?php

namespace Shared\Services;

use App\Models\Tenant;
use Shared\Services\TenantDatabaseService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Exception;

class TenantManagementService
{
    public function __construct(
        private TenantDatabaseService $tenantDatabaseService
    ) {}

    /**
     * Create a new tenant with database
     *
     * @param array $data
     * @return Tenant
     * @throws Exception
     */
    public function createTenant(array $data): Tenant
    {
        try {
            // Validate required fields
            $this->validateTenantData($data);

            // Generate database name
            $databaseName = $this->generateDatabaseName($data['name']);

            // Create tenant record
            $tenant = Tenant::create([
                'name' => $data['name'],
                'domain' => $data['domain'],
                'database_name' => $databaseName,
                'settings' => $data['settings'] ?? [],
                'is_active' => $data['is_active'] ?? true,
            ]);

            // Create tenant database
            $this->tenantDatabaseService->createTenantDatabase($tenant);

            // Clear cache
            $this->clearTenantCache($tenant);

            Log::info('Tenant created successfully', [
                'tenant_id' => $tenant->id,
                'name' => $tenant->name,
                'domain' => $tenant->domain,
            ]);

            return $tenant;

        } catch (Exception $e) {
            Log::error('Failed to create tenant', [
                'data' => $data,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Update tenant
     *
     * @param Tenant $tenant
     * @param array $data
     * @return Tenant
     * @throws Exception
     */
    public function updateTenant(Tenant $tenant, array $data): Tenant
    {
        try {
            // Update tenant record
            $tenant->update($data);

            // Clear cache
            $this->clearTenantCache($tenant);

            Log::info('Tenant updated successfully', [
                'tenant_id' => $tenant->id,
                'data' => $data,
            ]);

            return $tenant;

        } catch (Exception $e) {
            Log::error('Failed to update tenant', [
                'tenant_id' => $tenant->id,
                'data' => $data,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Delete tenant and its database
     *
     * @param Tenant $tenant
     * @return void
     * @throws Exception
     */
    public function deleteTenant(Tenant $tenant): void
    {
        try {
            // Drop tenant database
            $this->tenantDatabaseService->dropTenantDatabase($tenant);

            // Delete tenant record
            $tenant->delete();

            // Clear cache
            $this->clearTenantCache($tenant);

            Log::info('Tenant deleted successfully', [
                'tenant_id' => $tenant->id,
                'name' => $tenant->name,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to delete tenant', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Activate tenant
     *
     * @param Tenant $tenant
     * @return Tenant
     * @throws Exception
     */
    public function activateTenant(Tenant $tenant): Tenant
    {
        return $this->updateTenant($tenant, ['is_active' => true]);
    }

    /**
     * Deactivate tenant
     *
     * @param Tenant $tenant
     * @return Tenant
     * @throws Exception
     */
    public function deactivateTenant(Tenant $tenant): Tenant
    {
        return $this->updateTenant($tenant, ['is_active' => false]);
    }

    /**
     * Get tenant by ID or domain
     *
     * @param string $identifier
     * @return Tenant|null
     */
    public function getTenant(string $identifier): ?Tenant
    {
        return $this->tenantDatabaseService->getTenant($identifier);
    }

    /**
     * Get all tenants
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllTenants()
    {
        return Tenant::all();
    }

    /**
     * Get active tenants
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getActiveTenants()
    {
        return Tenant::where('is_active', true)->get();
    }

    /**
     * Check if tenant exists
     *
     * @param string $identifier
     * @return bool
     */
    public function tenantExists(string $identifier): bool
    {
        return $this->getTenant($identifier) !== null;
    }

    /**
     * Check if tenant is active
     *
     * @param string $identifier
     * @return bool
     */
    public function isTenantActive(string $identifier): bool
    {
        $tenant = $this->getTenant($identifier);
        return $tenant && $tenant->is_active;
    }

    /**
     * Get tenant statistics
     *
     * @param Tenant $tenant
     * @return array
     */
    public function getTenantStatistics(Tenant $tenant): array
    {
        try {
            // Switch to tenant database
            $this->tenantDatabaseService->switchToTenantDatabase($tenant->id);

            $stats = [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                'database_name' => $tenant->database_name,
                'is_active' => $tenant->is_active,
                'created_at' => $tenant->created_at,
                'updated_at' => $tenant->updated_at,
            ];

            // Get employee count
            if (class_exists('App\Models\Employee')) {
                $stats['employee_count'] = \App\Models\Employee::count();
            }

            // Get department count
            if (class_exists('App\Models\Department')) {
                $stats['department_count'] = \App\Models\Department::count();
            }

            // Get branch count
            if (class_exists('App\Models\Branch')) {
                $stats['branch_count'] = \App\Models\Branch::count();
            }

            // Get attendance records count
            if (class_exists('App\Models\AttendanceRecord')) {
                $stats['attendance_records_count'] = \App\Models\AttendanceRecord::count();
            }

            // Get leave requests count
            if (class_exists('App\Models\LeaveRequest')) {
                $stats['leave_requests_count'] = \App\Models\LeaveRequest::count();
            }

            // Switch back to central database
            $this->tenantDatabaseService->switchToCentralDatabase();

            return $stats;

        } catch (Exception $e) {
            Log::error('Failed to get tenant statistics', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);

            // Ensure we switch back to central database
            $this->tenantDatabaseService->switchToCentralDatabase();

            return [
                'tenant_id' => $tenant->id,
                'error' => 'Failed to get statistics',
            ];
        }
    }

    /**
     * Validate tenant data
     *
     * @param array $data
     * @return void
     * @throws Exception
     */
    private function validateTenantData(array $data): void
    {
        if (empty($data['name'])) {
            throw new Exception('Tenant name is required');
        }

        if (empty($data['domain'])) {
            throw new Exception('Tenant domain is required');
        }

        // Check if domain already exists
        if (Tenant::where('domain', $data['domain'])->exists()) {
            throw new Exception('Tenant domain already exists');
        }
    }

    /**
     * Generate database name
     *
     * @param string $tenantName
     * @return string
     */
    private function generateDatabaseName(string $tenantName): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $tenantName));
        $slug = trim($slug, '_');
        
        return 'hrms_tenant_' . $slug . '_' . time();
    }

    /**
     * Clear tenant cache
     *
     * @param Tenant $tenant
     * @return void
     */
    private function clearTenantCache(Tenant $tenant): void
    {
        Cache::forget('tenant_db_' . $tenant->id);
        Cache::forget('tenant_db_' . $tenant->domain);
    }
}
