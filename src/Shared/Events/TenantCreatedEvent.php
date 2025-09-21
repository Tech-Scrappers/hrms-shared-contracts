<?php

namespace Shared\Events;

use Shared\Contracts\EventInterface;

class TenantCreatedEvent implements EventInterface
{
    public function __construct(
        private array $tenant,
        private ?string $tenantId = null
    ) {
        $this->tenantId = $tenantId ?? $tenant['id'] ?? null;
    }
    
    public function getEventName(): string
    {
        return 'tenant.created';
    }
    
    public function getPayload(): array
    {
        return $this->tenant;
    }
    
    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }
}
