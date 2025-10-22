<?php

namespace Shared\Events;

use Shared\Contracts\EventInterface;

class TenantMigrationEvent implements EventInterface
{
    public function __construct(
        private array $tenant,
        private string $service,
        private ?string $tenantId = null
    ) {
        $this->tenantId = $tenantId ?? $tenant['id'] ?? null;
    }

    public function getEventName(): string
    {
        return 'tenant.migration.required';
    }

    public function getPayload(): array
    {
        return [
            'tenant' => $this->tenant,
            'service' => $this->service,
            'tenant_id' => $this->tenantId,
        ];
    }

    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }

    public function getService(): string
    {
        return $this->service;
    }
}
