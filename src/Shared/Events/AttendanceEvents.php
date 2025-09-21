<?php

namespace Shared\Events;

use Shared\Contracts\EventInterface;

class AttendanceCheckedInEvent implements EventInterface
{
    public function __construct(private array $attendanceData) {}
    
    public function getEventName(): string
    {
        return 'attendance.checked_in';
    }
    
    public function getPayload(): array
    {
        return $this->attendanceData;
    }
}

class AttendanceCheckedOutEvent implements EventInterface
{
    public function __construct(private array $attendanceData) {}
    
    public function getEventName(): string
    {
        return 'attendance.checked_out';
    }
    
    public function getPayload(): array
    {
        return $this->attendanceData;
    }
}

class AttendanceMarkedEvent implements EventInterface
{
    public function __construct(private array $attendanceData) {}
    
    public function getEventName(): string
    {
        return 'attendance.marked';
    }
    
    public function getPayload(): array
    {
        return $this->attendanceData;
    }
}

class AttendanceStatusChangedEvent implements EventInterface
{
    public function __construct(private array $attendanceData) {}
    
    public function getEventName(): string
    {
        return 'attendance.status_changed';
    }
    
    public function getPayload(): array
    {
        return $this->attendanceData;
    }
}

class LeaveRequestCreatedEvent implements EventInterface
{
    public function __construct(private array $leaveRequestData) {}
    
    public function getEventName(): string
    {
        return 'leave.requested';
    }
    
    public function getPayload(): array
    {
        return $this->leaveRequestData;
    }
}

class LeaveRequestApprovedEvent implements EventInterface
{
    public function __construct(private array $leaveRequestData) {}
    
    public function getEventName(): string
    {
        return 'leave.approved';
    }
    
    public function getPayload(): array
    {
        return $this->leaveRequestData;
    }
}

class LeaveRequestRejectedEvent implements EventInterface
{
    public function __construct(private array $leaveRequestData) {}
    
    public function getEventName(): string
    {
        return 'leave.rejected';
    }
    
    public function getPayload(): array
    {
        return $this->leaveRequestData;
    }
}

class LeaveRequestCancelledEvent implements EventInterface
{
    public function __construct(private array $leaveRequestData) {}
    
    public function getEventName(): string
    {
        return 'leave.cancelled';
    }
    
    public function getPayload(): array
    {
        return $this->leaveRequestData;
    }
}

class LeaveBalanceUpdatedEvent implements EventInterface
{
    public function __construct(private array $leaveBalanceData) {}
    
    public function getEventName(): string
    {
        return 'leave_balance.updated';
    }
    
    public function getPayload(): array
    {
        return $this->leaveBalanceData;
    }
}

class WorkScheduleCreatedEvent implements EventInterface
{
    public function __construct(private array $workScheduleData) {}
    
    public function getEventName(): string
    {
        return 'work_schedule.created';
    }
    
    public function getPayload(): array
    {
        return $this->workScheduleData;
    }
}

class WorkScheduleUpdatedEvent implements EventInterface
{
    public function __construct(private array $workScheduleData) {}
    
    public function getEventName(): string
    {
        return 'work_schedule.updated';
    }
    
    public function getPayload(): array
    {
        return $this->workScheduleData;
    }
}
