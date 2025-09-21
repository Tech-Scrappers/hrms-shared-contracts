<?php

namespace Shared\Base;

use Shared\Contracts\TenantAwareInterface;
use Shared\Traits\TenantAwareTrait;
use Shared\Traits\AuditLogTrait;

abstract class BaseService implements TenantAwareInterface
{
    use TenantAwareTrait, AuditLogTrait;
    
    protected function validateTenantContext(): void
    {
        $this->ensureTenantId();
    }
    
    protected function logServiceAction(string $action, array $data = []): void
    {
        $this->logAuditEvent($action, $data);
    }
    
    protected function handleServiceException(\Exception $e, string $action): void
    {
        $this->logAuditEvent('service_error', [
            'action' => $action,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        
        throw $e;
    }
}
