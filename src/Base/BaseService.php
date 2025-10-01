<?php

namespace Shared\Base;

use Shared\Contracts\TenantAwareInterface;
use Shared\Traits\AuditLogTrait;
use Shared\Traits\TenantAwareTrait;

abstract class BaseService implements TenantAwareInterface
{
    use AuditLogTrait, TenantAwareTrait;

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
