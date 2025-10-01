<?php

namespace Shared\Events;

class EmployeeUpdated extends BaseEvent
{
    public function getEventType(): string { return 'employee.employee.updated'; }
    public function getServiceName(): string { return 'employee'; }
}
