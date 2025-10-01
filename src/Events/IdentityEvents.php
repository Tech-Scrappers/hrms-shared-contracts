<?php

namespace Shared\Events;

use Shared\Events\BaseEvent;
use Shared\Contracts\EventInterface;

// User Events
class UserCreated extends BaseEvent
{
    protected function getEventType(): string { return 'identity.user.created'; }
    protected function getServiceName(): string { return 'identity'; }
}

class UserUpdated extends BaseEvent
{
    protected function getEventType(): string { return 'identity.user.updated'; }
    protected function getServiceName(): string { return 'identity'; }
}

class UserDeleted extends BaseEvent
{
    protected function getEventType(): string { return 'identity.user.deleted'; }
    protected function getServiceName(): string { return 'identity'; }
}

// Role Events
class RoleCreated extends BaseEvent
{
    protected function getEventType(): string { return 'identity.role.created'; }
    protected function getServiceName(): string { return 'identity'; }
}

class RoleUpdated extends BaseEvent
{
    protected function getEventType(): string { return 'identity.role.updated'; }
    protected function getServiceName(): string { return 'identity'; }
}

class RoleDeleted extends BaseEvent
{
    protected function getEventType(): string { return 'identity.role.deleted'; }
    protected function getServiceName(): string { return 'identity'; }
}

// Permission Events
class PermissionCreated extends BaseEvent
{
    protected function getEventType(): string { return 'identity.permission.created'; }
    protected function getServiceName(): string { return 'identity'; }
}

class PermissionUpdated extends BaseEvent
{
    protected function getEventType(): string { return 'identity.permission.updated'; }
    protected function getServiceName(): string { return 'identity'; }
}

class PermissionDeleted extends BaseEvent
{
    protected function getEventType(): string { return 'identity.permission.deleted'; }
    protected function getServiceName(): string { return 'identity'; }
}

// Tenant Events
class TenantCreatedEvent implements EventInterface
{
    public function __construct(private array $tenantData) {}

    public function getEventName(): string
    {
        return 'tenant.created';
    }

    public function getPayload(): array
    {
        return $this->tenantData;
    }
}

class TenantUpdatedEvent implements EventInterface
{
    public function __construct(private array $tenantData) {}

    public function getEventName(): string
    {
        return 'tenant.updated';
    }

    public function getPayload(): array
    {
        return $this->tenantData;
    }
}

class TenantDeletedEvent implements EventInterface
{
    public function __construct(private array $tenantData) {}

    public function getEventName(): string
    {
        return 'tenant.deleted';
    }

    public function getPayload(): array
    {
        return $this->tenantData;
    }
}

class UserCreatedEvent implements EventInterface
{
    public function __construct(private array $userData) {}

    public function getEventName(): string
    {
        return 'user.created';
    }

    public function getPayload(): array
    {
        return $this->userData;
    }
}

class UserUpdatedEvent implements EventInterface
{
    public function __construct(private array $userData) {}

    public function getEventName(): string
    {
        return 'user.updated';
    }

    public function getPayload(): array
    {
        return $this->userData;
    }
}

class UserDeletedEvent implements EventInterface
{
    public function __construct(private array $userData) {}

    public function getEventName(): string
    {
        return 'user.deleted';
    }

    public function getPayload(): array
    {
        return $this->userData;
    }
}

class ApiKeyGeneratedEvent implements EventInterface
{
    public function __construct(private array $apiKeyData) {}

    public function getEventName(): string
    {
        return 'api_key.generated';
    }

    public function getPayload(): array
    {
        return $this->apiKeyData;
    }
}

class ApiKeyRevokedEvent implements EventInterface
{
    public function __construct(private array $apiKeyData) {}

    public function getEventName(): string
    {
        return 'api_key.revoked';
    }

    public function getPayload(): array
    {
        return $this->apiKeyData;
    }
}
