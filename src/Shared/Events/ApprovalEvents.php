<?php

namespace Shared\Events;

class ApprovalWorkflowCreated extends BaseEvent { protected function getEventType(): string { return 'approval.workflow.created'; } protected function getServiceName(): string { return 'core'; } }
class ApprovalWorkflowUpdated extends BaseEvent { protected function getEventType(): string { return 'approval.workflow.updated'; } protected function getServiceName(): string { return 'core'; } }
class ApprovalWorkflowDeleted extends BaseEvent { protected function getEventType(): string { return 'approval.workflow.deleted'; } protected function getServiceName(): string { return 'core'; } }

class ApprovalRequestCreated extends BaseEvent { protected function getEventType(): string { return 'approval.request.created'; } protected function getServiceName(): string { return 'core'; } }
class ApprovalRequestSubmitted extends BaseEvent { protected function getEventType(): string { return 'approval.request.submitted'; } protected function getServiceName(): string { return 'core'; } }
class ApprovalRequestApproved extends BaseEvent { protected function getEventType(): string { return 'approval.request.approved'; } protected function getServiceName(): string { return 'core'; } }
class ApprovalRequestRejected extends BaseEvent { protected function getEventType(): string { return 'approval.request.rejected'; } protected function getServiceName(): string { return 'core'; } }
class ApprovalRequestCancelled extends BaseEvent { protected function getEventType(): string { return 'approval.request.cancelled'; } protected function getServiceName(): string { return 'core'; } }
class ApprovalActionTaken extends BaseEvent { protected function getEventType(): string { return 'approval.action.taken'; } protected function getServiceName(): string { return 'core'; } }

class ApprovalDelegationCreated extends BaseEvent { protected function getEventType(): string { return 'approval.delegation.created'; } protected function getServiceName(): string { return 'core'; } }
class ApprovalDelegationUpdated extends BaseEvent { protected function getEventType(): string { return 'approval.delegation.updated'; } protected function getServiceName(): string { return 'core'; } }
class ApprovalDelegationDeleted extends BaseEvent { protected function getEventType(): string { return 'approval.delegation.deleted'; } protected function getServiceName(): string { return 'core'; } }


