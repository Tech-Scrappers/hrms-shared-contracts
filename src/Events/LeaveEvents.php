<?php

namespace Shared\Events;

class LeaveTypeCreated extends BaseEvent { protected function getEventType(): string { return 'leave.type.created'; } protected function getServiceName(): string { return 'core'; } }
class LeaveTypeUpdated extends BaseEvent { protected function getEventType(): string { return 'leave.type.updated'; } protected function getServiceName(): string { return 'core'; } }
class LeaveTypeDeleted extends BaseEvent { protected function getEventType(): string { return 'leave.type.deleted'; } protected function getServiceName(): string { return 'core'; } }

class LeaveBalanceAdjusted extends BaseEvent { protected function getEventType(): string { return 'leave.balance.adjusted'; } protected function getServiceName(): string { return 'core'; } }

class LeaveRequestCreated extends BaseEvent { protected function getEventType(): string { return 'leave.request.created'; } protected function getServiceName(): string { return 'core'; } }
class LeaveRequestUpdated extends BaseEvent { protected function getEventType(): string { return 'leave.request.updated'; } protected function getServiceName(): string { return 'core'; } }
class LeaveRequestApproved extends BaseEvent { protected function getEventType(): string { return 'leave.request.approved'; } protected function getServiceName(): string { return 'core'; } }
class LeaveRequestRejected extends BaseEvent { protected function getEventType(): string { return 'leave.request.rejected'; } protected function getServiceName(): string { return 'core'; } }
class LeaveRequestCancelled extends BaseEvent { protected function getEventType(): string { return 'leave.request.cancelled'; } protected function getServiceName(): string { return 'core'; } }


