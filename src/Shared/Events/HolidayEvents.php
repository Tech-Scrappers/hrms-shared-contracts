<?php

namespace Shared\Events;

/**
 * Holiday Events
 */
class HolidayCreated extends BaseEvent
{
    protected function getEventType(): string
    {
        return 'holiday.created';
    }

    protected function getServiceName(): string
    {
        return 'core';
    }
}

class HolidayUpdated extends BaseEvent
{
    protected function getEventType(): string
    {
        return 'holiday.updated';
    }

    protected function getServiceName(): string
    {
        return 'core';
    }
}

class HolidayDeleted extends BaseEvent
{
    protected function getEventType(): string
    {
        return 'holiday.deleted';
    }

    protected function getServiceName(): string
    {
        return 'core';
    }
}

/**
 * Weekend Configuration Events
 */
class WeekendConfigurationCreated extends BaseEvent
{
    protected function getEventType(): string
    {
        return 'weekend.configuration.created';
    }

    protected function getServiceName(): string
    {
        return 'core';
    }
}

class WeekendConfigurationUpdated extends BaseEvent
{
    protected function getEventType(): string
    {
        return 'weekend.configuration.updated';
    }

    protected function getServiceName(): string
    {
        return 'core';
    }
}

class WeekendConfigurationDeleted extends BaseEvent
{
    protected function getEventType(): string
    {
        return 'weekend.configuration.deleted';
    }

    protected function getServiceName(): string
    {
        return 'core';
    }
}

class WeekendConfigurationSetDefault extends BaseEvent
{
    protected function getEventType(): string
    {
        return 'weekend.configuration.set_default';
    }

    protected function getServiceName(): string
    {
        return 'core';
    }
}
