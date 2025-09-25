<?php

namespace Shared\Events;

class UserUpdated extends BaseEvent
{
    protected function getEventType(): string { return 'identity.user.updated'; }
    protected function getServiceName(): string { return 'identity'; }
}


