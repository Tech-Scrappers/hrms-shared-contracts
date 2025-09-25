<?php

namespace Shared\Events;

class UserDeleted extends BaseEvent
{
    protected function getEventType(): string { return 'identity.user.deleted'; }
    protected function getServiceName(): string { return 'identity'; }
}


