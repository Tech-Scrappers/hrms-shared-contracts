<?php

namespace Shared\Helpers;

use Illuminate\Support\Str;

class UuidHelper
{
    public static function generateUuid(): string
    {
        return Str::uuid()->toString();
    }

    public static function isValidUuid(string $uuid): bool
    {
        return Str::isUuid($uuid);
    }

    public static function generateApiKey(): string
    {
        return 'ak_'.Str::random(32);
    }

    public static function generateEmployeeId(string $prefix = 'EMP'): string
    {
        return $prefix.'_'.strtoupper(Str::random(8));
    }
}
