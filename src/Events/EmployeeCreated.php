<?php

namespace Shared\Events;

class EmployeeCreated extends BaseEvent
{
    public function getEventType(): string { return 'employee.employee.created'; }
    public function getServiceName(): string { return 'employee'; }
}
