<?php

namespace Shared\Contracts;

interface EventInterface
{
    public function getEventName(): string;

    public function getPayload(): array;

    public function getTenantId(): ?string;
}
