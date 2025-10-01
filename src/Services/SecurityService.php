<?php

namespace Shared\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Carbon\Carbon;

class SecurityService
{
    /**
     * Log security events for audit and monitoring
     */
    public function logSecurityEvent(
        string $eventType,
        array $context,
        Request $request,
        array $additionalData = []
    ): void {
        $logData = array_merge([
            'event_type' => $eventType,
            'timestamp' => now()->toISOString(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_uri' => $request->getRequestUri(),
            'request_method' => $request->method(),
            'context' => $context,
        ], $additionalData);

        // Log to Laravel logs
        Log::warning("Security Event: {$eventType}", $logData);

        // Store in database for audit trail
        $this->storeSecurityEvent($logData);
    }

    /**
     * Store security event in database
     */
    private function storeSecurityEvent(array $logData): void
    {
        try {
            DB::connection('pgsql')->table('security_events')->insert([
                'event_type' => $logData['event_type'],
                'ip_address' => $logData['ip_address'],
                'user_agent' => $logData['user_agent'],
                'request_uri' => $logData['request_uri'],
                'request_method' => $logData['request_method'],
                'context' => json_encode($logData['context']),
                'additional_data' => json_encode($logData['additional_data'] ?? []),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Exception $e) {
            // If database logging fails, just log to file
            Log::error('Failed to store security event in database', [
                'error' => $e->getMessage(),
                'event_data' => $logData,
            ]);
        }
    }

    /**
     * Log authentication events
     */
    public function logAuthenticationEvent(
        string $eventType,
        array $user,
        Request $request,
        array $additionalData = []
    ): void {
        $this->logSecurityEvent($eventType, [
            'user_id' => $user['id'] ?? 'N/A',
            'user_email' => $user['email'] ?? 'N/A',
            'user_role' => $user['role'] ?? 'N/A',
            'tenant_id' => $user['tenant_id'] ?? 'N/A',
        ], $request, $additionalData);
    }

    /**
     * Log API key events
     */
    public function logApiKeyEvent(
        string $eventType,
        array $apiKey,
        Request $request,
        array $additionalData = []
    ): void {
        $this->logSecurityEvent($eventType, [
            'api_key_id' => $apiKey['id'] ?? 'N/A',
            'api_key_name' => $apiKey['name'] ?? 'N/A',
            'tenant_id' => $apiKey['tenant_id'] ?? 'N/A',
            'permissions' => $apiKey['permissions'] ?? [],
        ], $request, $additionalData);
    }

    /**
     * Log cross-tenant access attempts
     */
    public function logCrossTenantAccessAttempt(
        array $user,
        string $requestedTenantId,
        Request $request,
        string $resource = 'unknown'
    ): void {
        $this->logSecurityEvent('cross_tenant_access_attempt', [
            'user_id' => $user['id'] ?? 'N/A',
            'user_tenant_id' => $user['tenant_id'] ?? 'N/A',
            'requested_tenant_id' => $requestedTenantId,
            'resource' => $resource,
        ], $request, [
            'user_role' => $user['role'] ?? 'N/A',
            'user_email' => $user['email'] ?? 'N/A',
        ]);
    }

    /**
     * Log permission denied events
     */
    public function logPermissionDenied(
        array $user,
        string $requiredScope,
        Request $request,
        array $additionalData = []
    ): void {
        $this->logSecurityEvent('permission_denied', [
            'user_id' => $user['id'] ?? 'N/A',
            'user_role' => $user['role'] ?? 'N/A',
            'required_scope' => $requiredScope,
            'tenant_id' => $user['tenant_id'] ?? 'N/A',
        ], $request, $additionalData);
    }

    /**
     * Log suspicious activity
     */
    public function logSuspiciousActivity(
        string $activityType,
        array $context,
        Request $request,
        array $additionalData = []
    ): void {
        $this->logSecurityEvent('suspicious_activity', [
            'activity_type' => $activityType,
            'context' => $context,
        ], $request, $additionalData);
    }

    /**
     * Get security events for a tenant
     */
    public function getSecurityEvents(
        string $tenantId,
        int $limit = 100,
        int $offset = 0,
        ?string $eventType = null,
        ?Carbon $fromDate = null,
        ?Carbon $toDate = null
    ): array {
        $query = DB::connection('pgsql')
            ->table('security_events')
            ->where('context->tenant_id', $tenantId);

        if ($eventType) {
            $query->where('event_type', $eventType);
        }

        if ($fromDate) {
            $query->where('created_at', '>=', $fromDate);
        }

        if ($toDate) {
            $query->where('created_at', '<=', $toDate);
        }

        return $query->orderBy('created_at', 'desc')
                    ->limit($limit)
                    ->offset($offset)
                    ->get()
                    ->toArray();
    }

    /**
     * Get security statistics for a tenant
     */
    public function getSecurityStatistics(string $tenantId, int $days = 30): array
    {
        $fromDate = now()->subDays($days);

        $stats = DB::connection('pgsql')
            ->table('security_events')
            ->where('context->tenant_id', $tenantId)
            ->where('created_at', '>=', $fromDate)
            ->selectRaw('
                COUNT(*) as total_events,
                COUNT(CASE WHEN event_type = ? THEN 1 END) as cross_tenant_attempts,
                COUNT(CASE WHEN event_type = ? THEN 1 END) as permission_denied,
                COUNT(CASE WHEN event_type = ? THEN 1 END) as suspicious_activity,
                COUNT(CASE WHEN event_type = ? THEN 1 END) as api_key_events
            ', [
                'cross_tenant_access_attempt',
                'permission_denied',
                'suspicious_activity',
                'api_key_cross_tenant_access_attempt'
            ])
            ->first();

        return [
            'total_events' => (int) $stats->total_events,
            'cross_tenant_attempts' => (int) $stats->cross_tenant_attempts,
            'permission_denied' => (int) $stats->permission_denied,
            'suspicious_activity' => (int) $stats->suspicious_activity,
            'api_key_events' => (int) $stats->api_key_events,
            'period_days' => $days,
        ];
    }

    /**
     * Check for suspicious patterns
     */
    public function checkSuspiciousPatterns(string $tenantId, int $hours = 24): array
    {
        $fromTime = now()->subHours($hours);

        // Check for multiple failed authentication attempts
        $failedAuthAttempts = DB::connection('pgsql')
            ->table('security_events')
            ->where('context->tenant_id', $tenantId)
            ->where('event_type', 'authentication_failed')
            ->where('created_at', '>=', $fromTime)
            ->count();

        // Check for multiple cross-tenant access attempts
        $crossTenantAttempts = DB::connection('pgsql')
            ->table('security_events')
            ->where('context->tenant_id', $tenantId)
            ->where('event_type', 'cross_tenant_access_attempt')
            ->where('created_at', '>=', $fromTime)
            ->count();

        // Check for multiple permission denied events
        $permissionDenied = DB::connection('pgsql')
            ->table('security_events')
            ->where('context->tenant_id', $tenantId)
            ->where('event_type', 'permission_denied')
            ->where('created_at', '>=', $fromTime)
            ->count();

        $suspicious = [];

        if ($failedAuthAttempts > 5) {
            $suspicious[] = [
                'type' => 'multiple_failed_auth',
                'count' => $failedAuthAttempts,
                'severity' => 'high',
            ];
        }

        if ($crossTenantAttempts > 3) {
            $suspicious[] = [
                'type' => 'multiple_cross_tenant_attempts',
                'count' => $crossTenantAttempts,
                'severity' => 'critical',
            ];
        }

        if ($permissionDenied > 10) {
            $suspicious[] = [
                'type' => 'multiple_permission_denied',
                'count' => $permissionDenied,
                'severity' => 'medium',
            ];
        }

        return $suspicious;
    }
}
