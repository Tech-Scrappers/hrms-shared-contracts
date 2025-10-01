<?php

namespace Shared\Tests\Unit;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
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

    public function test_can_publish_event()
    {
        // Mock Redis
        Redis::shouldReceive('publish')
            ->once()
            ->with('hrms_events', \Mockery::type('string'));

        Redis::shouldReceive('lpush')
            ->once()
            ->with('hrms_events:queue', \Mockery::type('string'));

        Redis::shouldReceive('ltrim')
            ->once()
            ->with('hrms_events:queue', 0, 999);

        // Mock Log
        Log::shouldReceive('info')
            ->once()
            ->with('Event published', \Mockery::type('array'));

        $event = new EmployeeCreatedEvent([
            'id' => 'test-employee-id',
            'name' => 'Test Employee',
            'tenant_id' => 'test-tenant-id',
        ]);

        $this->eventBus->publish($event);

        $this->assertTrue(true); // If we get here without exception, test passes
    }

    public function test_can_subscribe_to_events()
    {
        // Mock Redis subscribe
        Redis::shouldReceive('subscribe')
            ->once()
            ->with(['hrms_events'], \Mockery::type('callable'));

        $callback = function ($payload, $metadata) {
            $this->assertEquals('test-employee-id', $payload['id']);
        };

        $this->eventBus->subscribe('employee.created', $callback);

        $this->assertTrue(true); // If we get here without exception, test passes
    }

    public function test_can_subscribe_to_multiple_events()
    {
        // Mock Redis subscribe
        Redis::shouldReceive('subscribe')
            ->once()
            ->with(['hrms_events'], \Mockery::type('callable'));

        $callback = function ($eventName, $payload, $metadata) {
            $this->assertContains($eventName, ['employee.created', 'employee.updated']);
        };

        $this->eventBus->subscribeToMultiple(['employee.created', 'employee.updated'], $callback);

        $this->assertTrue(true); // If we get here without exception, test passes
    }

    public function test_can_get_event_history()
    {
        $mockEvents = [
            json_encode(['event_name' => 'employee.created', 'payload' => ['id' => '1']]),
            json_encode(['event_name' => 'employee.updated', 'payload' => ['id' => '2']]),
        ];

        Redis::shouldReceive('lrange')
            ->once()
            ->with('hrms_events:queue', 0, 99)
            ->andReturn($mockEvents);

        $history = $this->eventBus->getEventHistory(100);

        $this->assertCount(2, $history);
        $this->assertEquals('employee.created', $history[0]['event_name']);
        $this->assertEquals('employee.updated', $history[1]['event_name']);
    }

    public function test_can_get_service_events()
    {
        $mockEvents = [
            ['event_name' => 'employee.created', 'metadata' => ['service' => 'test-service']],
            ['event_name' => 'employee.updated', 'metadata' => ['service' => 'other-service']],
        ];

        Redis::shouldReceive('lrange')
            ->once()
            ->with('hrms_events:queue', 0, 99)
            ->andReturn(array_map('json_encode', $mockEvents));

        $serviceEvents = $this->eventBus->getServiceEvents('test-service', 100);

        $this->assertCount(1, $serviceEvents);
        $this->assertEquals('employee.created', $serviceEvents[0]['event_name']);
    }

    public function test_can_clear_event_history()
    {
        Redis::shouldReceive('del')
            ->once()
            ->with('hrms_events:queue');

        $this->eventBus->clearEventHistory();

        $this->assertTrue(true); // If we get here without exception, test passes
    }

    public function test_handles_redis_errors_gracefully()
    {
        // Mock Redis to throw exception
        Redis::shouldReceive('publish')
            ->once()
            ->andThrow(new \Exception('Redis connection failed'));

        Log::shouldReceive('error')
            ->once()
            ->with('Failed to publish event', \Mockery::type('array'));

        $event = new EmployeeCreatedEvent(['id' => 'test-id']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Redis connection failed');

        $this->eventBus->publish($event);
    }

    public function test_event_metadata_includes_service_info()
    {
        Redis::shouldReceive('publish')
            ->once()
            ->with('hrms_events', \Mockery::on(function ($json) {
                $data = json_decode($json, true);

                return $data['metadata']['service'] === 'test-service' &&
                       isset($data['metadata']['timestamp']) &&
                       isset($data['metadata']['event_id']);
            }));

        Redis::shouldReceive('lpush')->once();
        Redis::shouldReceive('ltrim')->once();
        Log::shouldReceive('info')->once();

        $event = new EmployeeCreatedEvent(['id' => 'test-id']);
        $this->eventBus->publish($event);

        $this->assertTrue(true); // If we get here without exception, test passes
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}
