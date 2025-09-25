<?php

namespace Shared\Events;

/**
 * Shift Template Events
 */
class ShiftTemplateCreated extends BaseEvent
{
    protected function getEventType(): string
    {
        return 'shift.template.created';
    }

    protected function getServiceName(): string
    {
        return 'core';
    }
}

class ShiftTemplateUpdated extends BaseEvent
{
    protected function getEventType(): string
    {
        return 'shift.template.updated';
    }

    protected function getServiceName(): string
    {
        return 'core';
    }
}

class ShiftTemplateDeleted extends BaseEvent
{
    protected function getEventType(): string
    {
        return 'shift.template.deleted';
    }

    protected function getServiceName(): string
    {
        return 'core';
    }
}

/**
 * Shift Schedule Events
 */
class ShiftScheduleCreated extends BaseEvent
{
    protected function getEventType(): string
    {
        return 'shift.schedule.created';
    }

    protected function getServiceName(): string
    {
        return 'core';
    }
}

class ShiftScheduleUpdated extends BaseEvent
{
    protected function getEventType(): string
    {
        return 'shift.schedule.updated';
    }

    protected function getServiceName(): string
    {
        return 'core';
    }
}

class ShiftScheduleDeleted extends BaseEvent
{
    protected function getEventType(): string
    {
        return 'shift.schedule.deleted';
    }

    protected function getServiceName(): string
    {
        return 'core';
    }
}

/**
 * Employee Shift Events
 */
class EmployeeShiftAssigned extends BaseEvent
{
    protected function getEventType(): string
    {
        return 'employee.shift.assigned';
    }

    protected function getServiceName(): string
    {
        return 'core';
    }
}

class EmployeeShiftUpdated extends BaseEvent
{
    protected function getEventType(): string
    {
        return 'employee.shift.updated';
    }

    protected function getServiceName(): string
    {
        return 'core';
    }
}

class EmployeeShiftCancelled extends BaseEvent
{
    protected function getEventType(): string
    {
        return 'employee.shift.cancelled';
    }

    protected function getServiceName(): string
    {
        return 'core';
    }
}

class EmployeeShiftSwapped extends BaseEvent
{
    protected function getEventType(): string
    {
        return 'employee.shift.swapped';
    }

    protected function getServiceName(): string
    {
        return 'core';
    }
}
