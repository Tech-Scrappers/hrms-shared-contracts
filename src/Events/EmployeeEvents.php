<?php

namespace Shared\Events;

// Employee Events
class EmployeeCreated extends BaseEvent
{
    protected function getEventType(): string { return 'employee.employee.created'; }
    protected function getServiceName(): string { return 'employee'; }
}

class EmployeeUpdated extends BaseEvent
{
    protected function getEventType(): string { return 'employee.employee.updated'; }
    protected function getServiceName(): string { return 'employee'; }
}

class EmployeeDeleted extends BaseEvent
{
    protected function getEventType(): string { return 'employee.employee.deleted'; }
    protected function getServiceName(): string { return 'employee'; }
}

// Department Events
class DepartmentCreated extends BaseEvent
{
    protected function getEventType(): string { return 'employee.department.created'; }
    protected function getServiceName(): string { return 'employee'; }
}

class DepartmentUpdated extends BaseEvent
{
    protected function getEventType(): string { return 'employee.department.updated'; }
    protected function getServiceName(): string { return 'employee'; }
}

class DepartmentDeleted extends BaseEvent
{
    protected function getEventType(): string { return 'employee.department.deleted'; }
    protected function getServiceName(): string { return 'employee'; }
}

// Branch Events
class BranchCreated extends BaseEvent
{
    protected function getEventType(): string { return 'employee.branch.created'; }
    protected function getServiceName(): string { return 'employee'; }
}

class BranchUpdated extends BaseEvent
{
    protected function getEventType(): string { return 'employee.branch.updated'; }
    protected function getServiceName(): string { return 'employee'; }
}

class BranchDeleted extends BaseEvent
{
    protected function getEventType(): string { return 'employee.branch.deleted'; }
    protected function getServiceName(): string { return 'employee'; }
}

// Additional event classes

use Shared\Contracts\EventInterface;

class EmployeeCreatedEvent implements EventInterface
{
    public function __construct(private array $employeeData) {}

    public function getEventName(): string
    {
        return 'employee.created';
    }

    public function getPayload(): array
    {
        return $this->employeeData;
    }

    public function getTenantId(): ?string
    {
        return $this->employeeData['tenant_id'] ?? null;
    }
}

class EmployeeUpdatedEvent implements EventInterface
{
    public function __construct(private array $employeeData) {}

    public function getEventName(): string
    {
        return 'employee.updated';
    }

    public function getPayload(): array
    {
        return $this->employeeData;
    }

    public function getTenantId(): ?string
    {
        return $this->employeeData['tenant_id'] ?? null;
    }
}

class EmployeeDeletedEvent implements EventInterface
{
    public function __construct(private array $employeeData) {}

    public function getEventName(): string
    {
        return 'employee.deleted';
    }

    public function getPayload(): array
    {
        return $this->employeeData;
    }

    public function getTenantId(): ?string
    {
        return $this->employeeData['tenant_id'] ?? null;
    }
}

class EmployeeStatusChangedEvent implements EventInterface
{
    public function __construct(private array $employeeData) {}

    public function getEventName(): string
    {
        return 'employee.status_changed';
    }

    public function getPayload(): array
    {
        return $this->employeeData;
    }

    public function getTenantId(): ?string
    {
        return $this->employeeData['tenant_id'] ?? null;
    }
}

class DepartmentCreatedEvent implements EventInterface
{
    public function __construct(private array $departmentData) {}

    public function getEventName(): string
    {
        return 'department.created';
    }

    public function getPayload(): array
    {
        return $this->departmentData;
    }

    public function getTenantId(): ?string
    {
        return $this->departmentData['tenant_id'] ?? null;
    }
}

class DepartmentUpdatedEvent implements EventInterface
{
    public function __construct(private array $departmentData) {}

    public function getEventName(): string
    {
        return 'department.updated';
    }

    public function getPayload(): array
    {
        return $this->departmentData;
    }

    public function getTenantId(): ?string
    {
        return $this->departmentData['tenant_id'] ?? null;
    }
}

class DepartmentDeletedEvent implements EventInterface
{
    public function __construct(private array $departmentData) {}

    public function getEventName(): string
    {
        return 'department.deleted';
    }

    public function getPayload(): array
    {
        return $this->departmentData;
    }

    public function getTenantId(): ?string
    {
        return $this->departmentData['tenant_id'] ?? null;
    }
}

class BranchCreatedEvent implements EventInterface
{
    public function __construct(private array $branchData) {}

    public function getEventName(): string
    {
        return 'branch.created';
    }

    public function getPayload(): array
    {
        return $this->branchData;
    }

    public function getTenantId(): ?string
    {
        return $this->branchData['tenant_id'] ?? null;
    }
}

class BranchUpdatedEvent implements EventInterface
{
    public function __construct(private array $branchData) {}

    public function getEventName(): string
    {
        return 'branch.updated';
    }

    public function getPayload(): array
    {
        return $this->branchData;
    }

    public function getTenantId(): ?string
    {
        return $this->branchData['tenant_id'] ?? null;
    }
}

class BranchDeletedEvent implements EventInterface
{
    public function __construct(private array $branchData) {}

    public function getEventName(): string
    {
        return 'branch.deleted';
    }

    public function getPayload(): array
    {
        return $this->branchData;
    }

    public function getTenantId(): ?string
    {
        return $this->branchData['tenant_id'] ?? null;
    }
}
