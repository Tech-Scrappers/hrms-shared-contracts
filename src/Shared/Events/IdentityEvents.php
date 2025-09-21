<?php

namespace Shared\Events;

use Shared\Contracts\EventInterface;

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
