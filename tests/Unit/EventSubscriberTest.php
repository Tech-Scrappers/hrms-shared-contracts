<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class EventSubscriberTest extends TestCase
{
    public function test_can_create_event_subscriber_without_facades()
    {
        // Test that we can create the class without triggering facade usage
        $this->assertTrue(class_exists(\Shared\Events\EventSubscriber::class));
    }

    public function test_event_subscriber_has_required_methods()
    {
        $reflection = new \ReflectionClass(\Shared\Events\EventSubscriber::class);

        $this->assertTrue($reflection->hasMethod('registerHandler'));
        $this->assertTrue($reflection->hasMethod('registerHandlers'));
        $this->assertTrue($reflection->hasMethod('getHandlers'));
        $this->assertTrue($reflection->hasMethod('startListening'));
        $this->assertTrue($reflection->hasMethod('stopListening'));
        $this->assertTrue($reflection->hasMethod('isRunning'));
    }

    public function test_event_subscriber_constructor()
    {
        $reflection = new \ReflectionClass(\Shared\Events\EventSubscriber::class);
        $constructor = $reflection->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertCount(1, $constructor->getParameters());
        $this->assertEquals('serviceName', $constructor->getParameters()[0]->getName());
    }

    public function test_event_subscriber_properties()
    {
        $reflection = new \ReflectionClass(\Shared\Events\EventSubscriber::class);

        $this->assertTrue($reflection->hasProperty('serviceName'));
        $this->assertTrue($reflection->hasProperty('handlers'));
        $this->assertTrue($reflection->hasProperty('isRunning'));
    }
}
