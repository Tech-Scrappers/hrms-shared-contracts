<?php

namespace Shared\Events;

/**
 * Attendance Events
 */
class AttendanceCheckIn extends BaseEvent
{
    protected function getEventType(): string
    {
        return 'attendance.check_in';
    }

    protected function getServiceName(): string
    {
        return 'core';
    }
}

class AttendanceCheckOut extends BaseEvent
{
    protected function getEventType(): string
    {
        return 'attendance.check_out';
    }

    protected function getServiceName(): string
    {
        return 'core';
    }
}

class AttendanceBreakStart extends BaseEvent
{
    protected function getEventType(): string
    {
        return 'attendance.break_start';
    }

    protected function getServiceName(): string
    {
        return 'core';
    }
}

class AttendanceBreakEnd extends BaseEvent
{
    protected function getEventType(): string
    {
        return 'attendance.break_end';
    }

    protected function getServiceName(): string
    {
        return 'core';
    }
}

/**
 * Manual Entry Request Events
 */
class ManualEntryRequestCreated extends BaseEvent
{
    protected function getEventType(): string
    {
        return 'manual_entry.request_created';
    }

    protected function getServiceName(): string
    {
        return 'core';
    }
}

class ManualEntryRequestApproved extends BaseEvent
{
    protected function getEventType(): string
    {
        return 'manual_entry.request_approved';
    }

    protected function getServiceName(): string
    {
        return 'core';
    }
}

class ManualEntryRequestRejected extends BaseEvent
{
    protected function getEventType(): string
    {
        return 'manual_entry.request_rejected';
    }

    protected function getServiceName(): string
    {
        return 'core';
    }
}

class ManualEntryRequestUpdated extends BaseEvent
{
    protected function getEventType(): string
    {
        return 'manual_entry.request_updated';
    }

    protected function getServiceName(): string
    {
        return 'core';
    }
}

class ManualEntryRequestDeleted extends BaseEvent
{
    protected function getEventType(): string
    {
        return 'manual_entry.request_deleted';
    }

    protected function getServiceName(): string
    {
        return 'core';
    }
}