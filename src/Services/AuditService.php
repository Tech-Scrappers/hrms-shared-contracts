<?php

namespace Shared\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Shared\Models\AuditLog;

/**
 * AuditService
 * 
 * Handles audit logging for model changes and system events
 */
class AuditService
{
    /**
     * Log a model change.
     *
     * @param  Model  $model
     * @param  string  $action
     * @param  array|null  $oldValues
     * @param  array|null  $newValues
     * @return AuditLog|null
     */
    public function logModelChange(
        Model $model, 
        string $action, 
        ?array $oldValues = null, 
        ?array $newValues = null
    ): ?AuditLog {
        try {
            // Skip if audit logging is disabled
            if (config('audit.disabled', false)) {
                return null;
            }

            // Get tenant ID from model or request
            $tenantId = $this->getTenantId($model);

            if (!$tenantId) {
                Log::warning('Cannot log audit event: No tenant ID found', [
                    'model' => get_class($model),
                    'action' => $action,
                ]);
                return null;
            }

            // Get old and new values
            $oldValues = $oldValues ?? $this->getOldValues($model, $action);
            $newValues = $newValues ?? $this->getNewValues($model, $action);

            // Create audit log
            $auditLog = AuditLog::create([
                'tenant_id' => $tenantId,
                'table_name' => $model->getTable(),
                'record_id' => $model->getKey(),
                'action' => $action,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'user_id' => $this->getCurrentUserId(),
                'ip_address' => $this->getClientIpAddress(),
                'user_agent' => $this->getUserAgent(),
            ]);

            Log::debug('Audit event logged', [
                'audit_log_id' => $auditLog->id,
                'model' => get_class($model),
                'action' => $action,
                'tenant_id' => $tenantId,
            ]);

            return $auditLog;

        } catch (\Exception $e) {
            Log::error('Failed to log audit event', [
                'model' => get_class($model),
                'action' => $action,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Log a custom audit event.
     *
     * @param  string  $tenantId
     * @param  string  $tableName
     * @param  string  $recordId
     * @param  string  $action
     * @param  array|null  $oldValues
     * @param  array|null  $newValues
     * @param  string|null  $userId
     * @return AuditLog|null
     */
    public function logCustomEvent(
        string $tenantId,
        string $tableName,
        string $recordId,
        string $action,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $userId = null
    ): ?AuditLog {
        try {
            // Skip if audit logging is disabled
            if (config('audit.disabled', false)) {
                return null;
            }

            $auditLog = AuditLog::create([
                'tenant_id' => $tenantId,
                'table_name' => $tableName,
                'record_id' => $recordId,
                'action' => $action,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'user_id' => $userId ?? $this->getCurrentUserId(),
                'ip_address' => $this->getClientIpAddress(),
                'user_agent' => $this->getUserAgent(),
            ]);

            Log::debug('Custom audit event logged', [
                'audit_log_id' => $auditLog->id,
                'table_name' => $tableName,
                'action' => $action,
                'tenant_id' => $tenantId,
            ]);

            return $auditLog;

        } catch (\Exception $e) {
            Log::error('Failed to log custom audit event', [
                'table_name' => $tableName,
                'action' => $action,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get audit logs for a specific model.
     *
     * @param  Model  $model
     * @param  int  $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getModelAuditLogs(Model $model, int $limit = 50)
    {
        return AuditLog::where('table_name', $model->getTable())
            ->where('record_id', $model->getKey())
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get audit logs for a specific tenant.
     *
     * @param  string  $tenantId
     * @param  int  $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getTenantAuditLogs(string $tenantId, int $limit = 100)
    {
        return AuditLog::where('tenant_id', $tenantId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get audit logs for a specific table.
     *
     * @param  string  $tenantId
     * @param  string  $tableName
     * @param  int  $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getTableAuditLogs(string $tenantId, string $tableName, int $limit = 100)
    {
        return AuditLog::where('tenant_id', $tenantId)
            ->where('table_name', $tableName)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get audit logs for a specific user.
     *
     * @param  string  $tenantId
     * @param  string  $userId
     * @param  int  $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getUserAuditLogs(string $tenantId, string $userId, int $limit = 100)
    {
        return AuditLog::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get audit logs within a date range.
     *
     * @param  string  $tenantId
     * @param  string  $startDate
     * @param  string  $endDate
     * @param  int  $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAuditLogsInDateRange(
        string $tenantId, 
        string $startDate, 
        string $endDate, 
        int $limit = 1000
    ) {
        return AuditLog::where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get audit statistics for a tenant.
     *
     * @param  string  $tenantId
     * @param  string|null  $startDate
     * @param  string|null  $endDate
     * @return array
     */
    public function getAuditStatistics(string $tenantId, ?string $startDate = null, ?string $endDate = null): array
    {
        $query = AuditLog::where('tenant_id', $tenantId);

        if ($startDate && $endDate) {
            $query->whereBetween('created_at', [$startDate, $endDate]);
        }

        $totalLogs = $query->count();
        $actionCounts = $query->select('action', DB::raw('count(*) as count'))
            ->groupBy('action')
            ->pluck('count', 'action')
            ->toArray();

        $tableCounts = $query->select('table_name', DB::raw('count(*) as count'))
            ->groupBy('table_name')
            ->pluck('count', 'table_name')
            ->toArray();

        $userCounts = $query->whereNotNull('user_id')
            ->select('user_id', DB::raw('count(*) as count'))
            ->groupBy('user_id')
            ->pluck('count', 'user_id')
            ->toArray();

        return [
            'total_logs' => $totalLogs,
            'action_counts' => $actionCounts,
            'table_counts' => $tableCounts,
            'user_counts' => $userCounts,
            'date_range' => [
                'start' => $startDate,
                'end' => $endDate,
            ],
        ];
    }

    /**
     * Clean up old audit logs.
     *
     * @param  string  $tenantId
     * @param  int  $daysToKeep
     * @return int Number of logs deleted
     */
    public function cleanupOldAuditLogs(string $tenantId, int $daysToKeep = 90): int
    {
        $cutoffDate = now()->subDays($daysToKeep);

        return AuditLog::where('tenant_id', $tenantId)
            ->where('created_at', '<', $cutoffDate)
            ->delete();
    }

    /**
     * Get the tenant ID from the model or request.
     *
     * @param  Model  $model
     * @return string|null
     */
    private function getTenantId(Model $model): ?string
    {
        // Try to get tenant_id from the model
        if (isset($model->tenant_id)) {
            return $model->tenant_id;
        }

        // Try to get tenant_id from request
        if (Request::has('tenant_id')) {
            return Request::get('tenant_id');
        }

        // Try to get tenant_id from authenticated user
        $user = Auth::user();
        if ($user && isset($user->tenant_id)) {
            return $user->tenant_id;
        }

        return null;
    }

    /**
     * Get the old values for the model.
     *
     * @param  Model  $model
     * @param  string  $action
     * @return array|null
     */
    private function getOldValues(Model $model, string $action): ?array
    {
        if (in_array($action, ['created', 'restored'])) {
            return null;
        }

        return $model->getOriginal();
    }

    /**
     * Get the new values for the model.
     *
     * @param  Model  $model
     * @param  string  $action
     * @return array|null
     */
    private function getNewValues(Model $model, string $action): ?array
    {
        if ($action === 'deleted') {
            return null;
        }

        return $model->getAttributes();
    }

    /**
     * Get the current user ID.
     *
     * @return string|null
     */
    private function getCurrentUserId(): ?string
    {
        $user = Auth::user();
        return $user ? $user->getKey() : null;
    }

    /**
     * Get the client IP address.
     *
     * @return string|null
     */
    private function getClientIpAddress(): ?string
    {
        return Request::ip();
    }

    /**
     * Get the user agent.
     *
     * @return string|null
     */
    private function getUserAgent(): ?string
    {
        return Request::userAgent();
    }
}
