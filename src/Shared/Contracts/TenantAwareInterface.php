<?php

namespace Shared\Contracts;

interface TenantAwareInterface
{
    public function getTenantId(): string;
    public function setTenantId(string $tenantId): void;
}
