<?php

namespace Shared\Events;

class UserCreated extends BaseEvent
{
    protected function getEventType(): string { return 'identity.user.created'; }
    protected function getServiceName(): string { return 'identity'; }
}


