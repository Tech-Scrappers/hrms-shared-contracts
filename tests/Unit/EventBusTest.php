<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Shared\Events\EmployeeCreatedEvent;
use Shared\Events\EventBus;

class EventBusTest extends TestCase
{
    private EventBus $eventBus;

    private string $serviceName = 'test-service';

    protected function setUp(): void
    {
        parent::setUp();
        $this->eventBus = new EventBus($this->serviceName);
    }

    public function test_can_create_event_bus()
    {
        $this->assertInstanceOf(EventBus::class, $this->eventBus);
    }

    public function test_can_create_employee_created_event()
    {
        $event = new EmployeeCreatedEvent([
            'id' => 'test-employee-id',
            'name' => 'Test Employee',
            'tenant_id' => 'test-tenant-id',
        ]);

        $this->assertInstanceOf(EmployeeCreatedEvent::class, $event);
    }

    public function test_event_has_correct_payload()
    {
        $payload = [
            'id' => 'test-employee-id',
            'name' => 'Test Employee',
            'tenant_id' => 'test-tenant-id',
        ];

        $event = new EmployeeCreatedEvent($payload);

        $this->assertEquals($payload, $event->getPayload());
    }

    public function test_event_has_correct_name()
    {
        $event = new EmployeeCreatedEvent(['id' => 'test-id']);

        $this->assertEquals('employee.created', $event->getEventName());
    }

    public function test_event_has_tenant_id()
    {
        $event = new EmployeeCreatedEvent([
            'id' => 'test-id',
            'tenant_id' => 'test-tenant-123',
        ]);

        $this->assertEquals('test-tenant-123', $event->getTenantId());
    }

    public function test_event_tenant_id_from_constructor()
    {
        $event = new EmployeeCreatedEvent(
            ['id' => 'test-id'],
            'constructor-tenant-123'
        );

        $this->assertEquals('constructor-tenant-123', $event->getTenantId());
    }

    public function test_event_tenant_id_null_when_not_provided()
    {
        $event = new EmployeeCreatedEvent(['id' => 'test-id']);

        $this->assertNull($event->getTenantId());
    }

    public function test_event_implements_event_interface()
    {
        $event = new EmployeeCreatedEvent(['id' => 'test-id']);

        $this->assertInstanceOf(\Shared\Contracts\EventInterface::class, $event);
    }

    public function test_event_interface_methods()
    {
        $payload = ['id' => 'test-id', 'name' => 'Test'];
        $tenantId = 'test-tenant';

        $event = new EmployeeCreatedEvent($payload, $tenantId);

        $this->assertEquals('employee.created', $event->getEventName());
        $this->assertEquals($payload, $event->getPayload());
        $this->assertEquals($tenantId, $event->getTenantId());
    }
}
