<?php

namespace Shared\Traits;

use Illuminate\Support\Facades\Log;

trait AuditLogTrait
{
    protected function logAuditEvent(string $action, array $data = [], ?string $userId = null): void
    {
        Log::channel('audit')->info('Audit Event', [
            'action' => $action,
            'user_id' => $userId ?? auth()->id(),
            'tenant_id' => $this->getTenantId(),
            'data' => $data,
            'timestamp' => now()->toISOString(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    protected function logSecurityEvent(string $event, array $data = []): void
    {
        Log::channel('security')->warning('Security Event', [
            'event' => $event,
            'tenant_id' => $this->getTenantId(),
            'data' => $data,
            'timestamp' => now()->toISOString(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
