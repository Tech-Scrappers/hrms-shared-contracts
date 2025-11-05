<?php

namespace Shared\Events;

// Approval Workflow Events
class ApprovalWorkflowCreated extends BaseEvent
{
    protected function getEventType(): string { return 'core.approval_workflow.created'; }
    protected function getServiceName(): string { return 'core'; }
}

class ApprovalWorkflowUpdated extends BaseEvent
{
    protected function getEventType(): string { return 'core.approval_workflow.updated'; }
    protected function getServiceName(): string { return 'core'; }
}

class ApprovalWorkflowDeleted extends BaseEvent
{
    protected function getEventType(): string { return 'core.approval_workflow.deleted'; }
    protected function getServiceName(): string { return 'core'; }
}

// Approval Request Events
class ApprovalRequestCreated extends BaseEvent
{
    protected function getEventType(): string { return 'core.approval_request.created'; }
    protected function getServiceName(): string { return 'core'; }
}

class ApprovalRequestSubmitted extends BaseEvent
{
    protected function getEventType(): string { return 'core.approval_request.submitted'; }
    protected function getServiceName(): string { return 'core'; }
}

class ApprovalRequestApproved extends BaseEvent
{
    protected function getEventType(): string { return 'core.approval_request.approved'; }
    protected function getServiceName(): string { return 'core'; }
}

class ApprovalRequestRejected extends BaseEvent
{
    protected function getEventType(): string { return 'core.approval_request.rejected'; }
    protected function getServiceName(): string { return 'core'; }
}

class ApprovalRequestCancelled extends BaseEvent
{
    protected function getEventType(): string { return 'core.approval_request.cancelled'; }
    protected function getServiceName(): string { return 'core'; }
}

class ApprovalActionTaken extends BaseEvent
{
    protected function getEventType(): string { return 'core.approval_action.taken'; }
    protected function getServiceName(): string { return 'core'; }
}

// Approval Delegation Events
class ApprovalDelegationCreated extends BaseEvent
{
    protected function getEventType(): string { return 'core.approval_delegation.created'; }
    protected function getServiceName(): string { return 'core'; }
}

class ApprovalDelegationUpdated extends BaseEvent
{
    protected function getEventType(): string { return 'core.approval_delegation.updated'; }
    protected function getServiceName(): string { return 'core'; }
}

class ApprovalDelegationDeleted extends BaseEvent
{
    protected function getEventType(): string { return 'core.approval_delegation.deleted'; }
    protected function getServiceName(): string { return 'core'; }
}


