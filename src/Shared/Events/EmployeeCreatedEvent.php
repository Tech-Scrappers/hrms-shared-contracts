<?php

namespace Shared\Events;

use Shared\Contracts\EventInterface;

class EmployeeCreatedEvent implements EventInterface
{
    public function __construct(
        private array $employee,
        private ?string $tenantId = null
    ) {
        $this->tenantId = $tenantId ?? $employee['tenant_id'] ?? null;
    }
    
    public function getEventName(): string
    {
        return 'employee.created';
    }
    
    public function getPayload(): array
    {
        return $this->employee;
    }
    
    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }
}
