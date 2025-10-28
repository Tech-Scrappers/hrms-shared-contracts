<?php

namespace Shared\Services;

use Illuminate\Support\Facades\Log;
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
     * Store security event via Identity Service API
     */
    private function storeSecurityEvent(array $logData): void
    {
        try {
            // Send to Identity Service via SecurityEventApiClient
            // This is fire-and-forget to avoid blocking the main application flow
            app(SecurityEventApiClient::class)->logSecurityEvent([
                'event_type' => $logData['event_type'],
                'tenant_id' => $logData['context']['tenant_id'] ?? null,
                'user_id' => $logData['context']['user_id'] ?? null,
                'ip_address' => $logData['ip_address'],
                'user_agent' => $logData['user_agent'],
                'request_uri' => $logData['request_uri'],
                'request_method' => $logData['request_method'],
                'context' => $logData['context'],
                'additional_data' => $logData['additional_data'] ?? [],
            ]);
        } catch (\Exception $e) {
            // Silently fail - SecurityEventApiClient already has fallback logging
            Log::debug('Security event API client handled event', [
                'event_type' => $logData['event_type'] ?? 'unknown',
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
     * Get security events for a tenant via Identity Service API
     */
    public function getSecurityEvents(
        string $tenantId,
        int $limit = 100,
        int $offset = 0,
        ?string $eventType = null,
        ?Carbon $fromDate = null,
        ?Carbon $toDate = null
    ): array {
        $filters = [
            'limit' => $limit,
            'offset' => $offset,
        ];
        
        if ($eventType) {
            $filters['event_type'] = $eventType;
        }
        
        if ($fromDate) {
            $filters['from_date'] = $fromDate->toISOString();
        }
        
        if ($toDate) {
            $filters['to_date'] = $toDate->toISOString();
        }
        
        return app(SecurityEventApiClient::class)->getSecurityEvents($tenantId, $filters);
    }

    /**
     * Get security statistics for a tenant via Identity Service API
     */
    public function getSecurityStatistics(string $tenantId, int $days = 30): array
    {
        return app(SecurityEventApiClient::class)->getSecurityStatistics($tenantId, $days);
    }

    /**
     * Check for suspicious patterns via Identity Service API
     */
    public function checkSuspiciousPatterns(string $tenantId, int $hours = 24): array
    {
        return app(SecurityEventApiClient::class)->checkSuspiciousPatterns($tenantId, $hours);
    }
}
