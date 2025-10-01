<?php

namespace Shared\Traits;

trait TenantAwareTrait
{
    protected ?string $tenantId = null;

    public function getTenantId(): string
    {
        // Try to get from property first
        if ($this->tenantId) {
            return $this->tenantId;
        }

        // Try to get from request
        if (request()->has('tenant_id')) {
            $this->tenantId = request()->get('tenant_id');

            return $this->tenantId ?? '';
        }

        return '';
    }

    public function setTenantId(string $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    protected function ensureTenantId(): void
    {
        // Try to get tenant ID from request if not set
        $tenantId = $this->getTenantId();

        \Log::info('ensureTenantId debug', [
            'tenant_id' => $this->tenantId,
            'tenant_id_type' => gettype($this->tenantId),
            'tenant_id_empty' => empty($this->tenantId),
            'request_has_tenant_id' => request()->has('tenant_id'),
            'request_tenant_id' => request()->get('tenant_id'),
            'getTenantId_result' => $tenantId,
        ]);

        if (! $tenantId) {
            throw new \InvalidArgumentException('Tenant ID is required for this operation');
        }
    }
}
