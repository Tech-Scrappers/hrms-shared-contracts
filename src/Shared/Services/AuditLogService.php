<?php

namespace Shared\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AuditLogService
{
    private const AUDIT_LOG_CHANNEL = 'audit';
    private const CACHE_PREFIX = 'audit_log_';
    private const CACHE_TTL = 86400; // 24 hours

    /**
     * Log authentication events
     */
    public function logAuthenticationEvent(
        string $event,
        string $userId = null,
        string $tenantId = null,
        Request $request = null,
        array $metadata = []
    ): void {
        $this->logEvent([
            'event_type' => 'authentication',
            'event' => $event,
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'metadata' => $metadata,
        ]);
    }

    /**
     * Log API key events
     */
    public function logApiKeyEvent(
        string $event,
        string $apiKeyId = null,
        string $tenantId = null,
        Request $request = null,
        array $metadata = []
    ): void {
        $this->logEvent([
            'event_type' => 'api_key',
            'event' => $event,
            'api_key_id' => $apiKeyId,
            'tenant_id' => $tenantId,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'metadata' => $metadata,
        ]);
    }

    /**
     * Log tenant events
     */
    public function logTenantEvent(
        string $event,
        string $tenantId,
        string $userId = null,
        Request $request = null,
        array $metadata = []
    ): void {
        $this->logEvent([
            'event_type' => 'tenant',
            'event' => $event,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'metadata' => $metadata,
        ]);
    }

    /**
     * Log data access events
     */
    public function logDataAccessEvent(
        string $event,
        string $resourceType,
        string $resourceId = null,
        string $tenantId = null,
        string $userId = null,
        Request $request = null,
        array $metadata = []
    ): void {
        $this->logEvent([
            'event_type' => 'data_access',
            'event' => $event,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'metadata' => $metadata,
        ]);
    }

    /**
     * Log security events
     */
    public function logSecurityEvent(
        string $event,
        string $severity = 'medium',
        string $tenantId = null,
        string $userId = null,
        Request $request = null,
        array $metadata = []
    ): void {
        $this->logEvent([
            'event_type' => 'security',
            'event' => $event,
            'severity' => $severity,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'metadata' => $metadata,
        ]);
    }

    /**
     * Log system events
     */
    public function logSystemEvent(
        string $event,
        string $service = null,
        string $tenantId = null,
        array $metadata = []
    ): void {
        $this->logEvent([
            'event_type' => 'system',
            'event' => $event,
            'service' => $service,
            'tenant_id' => $tenantId,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Log generic event
     */
    private function logEvent(array $eventData): void
    {
        // Add common fields
        $eventData = array_merge($eventData, [
            'id' => Str::uuid()->toString(),
            'timestamp' => now()->toISOString(),
            'request_id' => request()->header('X-Request-ID') ?: Str::uuid()->toString(),
            'correlation_id' => request()->header('X-Correlation-ID') ?: Str::uuid()->toString(),
        ]);

        // Sanitize sensitive data
        $eventData = $this->sanitizeEventData($eventData);

        // Log to audit channel
        Log::channel(self::AUDIT_LOG_CHANNEL)->info('Audit Event', $eventData);

        // Cache for quick access
        $this->cacheEvent($eventData);
    }

    /**
     * Sanitize event data to remove sensitive information
     */
    private function sanitizeEventData(array $eventData): array
    {
        $sensitiveFields = [
            'password',
            'token',
            'secret',
            'key',
            'authorization',
            'cookie',
            'session',
        ];

        foreach ($eventData as $key => $value) {
            if (is_array($value)) {
                $eventData[$key] = $this->sanitizeEventData($value);
            } elseif (is_string($value)) {
                foreach ($sensitiveFields as $field) {
                    if (stripos($key, $field) !== false) {
                        $eventData[$key] = '[REDACTED]';
                        break;
                    }
                }
            }
        }

        return $eventData;
    }

    /**
     * Cache event for quick access
     */
    private function cacheEvent(array $eventData): void
    {
        $cacheKey = self::CACHE_PREFIX . $eventData['id'];
        Cache::put($cacheKey, $eventData, self::CACHE_TTL);
    }

    /**
     * Get audit events for a tenant
     */
    public function getTenantAuditEvents(
        string $tenantId,
        int $limit = 100,
        int $offset = 0,
        array $filters = []
    ): array {
        // This would typically query a dedicated audit log database
        // For now, we'll return cached events
        $events = [];
        
        // In a real implementation, you would query your audit log storage
        // This is a placeholder for the structure
        
        return [
            'events' => $events,
            'total' => count($events),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Get security events by severity
     */
    public function getSecurityEvents(
        string $severity = null,
        int $limit = 100,
        int $offset = 0
    ): array {
        // This would typically query a dedicated audit log database
        // For now, we'll return cached events
        $events = [];
        
        return [
            'events' => $events,
            'total' => count($events),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Get audit event by ID
     */
    public function getAuditEvent(string $eventId): ?array
    {
        $cacheKey = self::CACHE_PREFIX . $eventId;
        return Cache::get($cacheKey);
    }

    /**
     * Export audit events for compliance
     */
    public function exportAuditEvents(
        string $tenantId = null,
        string $startDate = null,
        string $endDate = null,
        string $format = 'json'
    ): string {
        // This would generate a compliance-ready export
        // For now, return a placeholder
        return json_encode([
            'export_id' => Str::uuid()->toString(),
            'generated_at' => now()->toISOString(),
            'tenant_id' => $tenantId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'format' => $format,
            'events' => [],
        ]);
    }
}
