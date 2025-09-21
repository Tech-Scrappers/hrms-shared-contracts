<?php

namespace Shared\Events;

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
}
