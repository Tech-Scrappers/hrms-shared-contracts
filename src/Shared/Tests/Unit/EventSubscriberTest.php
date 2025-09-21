<?php

namespace Shared\Tests\Unit;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\TestCase;
use Shared\Events\EventSubscriber;

class EventSubscriberTest extends TestCase
{
    private EventSubscriber $eventSubscriber;

    private string $serviceName = 'test-service';

    protected function setUp(): void
    {
        parent::setUp();
        $this->eventSubscriber = new EventSubscriber($this->serviceName);
    }

    public function test_can_register_event_handler()
    {
        $handler = function ($payload, $metadata) {
            return 'handled';
        };

        $this->eventSubscriber->registerHandler('employee.created', $handler);

        $handlers = $this->eventSubscriber->getHandlers();
        $this->assertContains('employee.created', $handlers);
    }

    public function test_can_register_multiple_handlers()
    {
        $handlers = [
            'employee.created' => function ($payload, $metadata) {
                return 'created';
            },
            'employee.updated' => function ($payload, $metadata) {
                return 'updated';
            },
        ];

        $this->eventSubscriber->registerHandlers($handlers);

        $registeredHandlers = $this->eventSubscriber->getHandlers();
        $this->assertCount(2, $registeredHandlers);
        $this->assertContains('employee.created', $registeredHandlers);
        $this->assertContains('employee.updated', $registeredHandlers);
    }

    public function test_can_start_listening()
    {
        Log::shouldReceive('info')
            ->once()
            ->with('Event subscriber started', \Mockery::type('array'));

        Redis::shouldReceive('subscribe')
            ->once()
            ->with(['hrms_events'], \Mockery::type('callable'));

        $this->eventSubscriber->startListening();

        $this->assertTrue($this->eventSubscriber->isRunning());
    }

    public function test_can_stop_listening()
    {
        Log::shouldReceive('info')
            ->once()
            ->with('Event subscriber stopped', \Mockery::type('array'));

        $this->eventSubscriber->stopListening();

        $this->assertFalse($this->eventSubscriber->isRunning());
    }

    public function test_handles_invalid_event_data()
    {
        Log::shouldReceive('warning')
            ->once()
            ->with('Invalid event data received', \Mockery::type('array'));

        $this->eventSubscriber->registerHandler('employee.created', function ($payload, $metadata) {
            return 'handled';
        });

        // Simulate invalid event data
        $this->eventSubscriber->handleEvent('invalid-json');

        $this->assertTrue(true); // If we get here without exception, test passes
    }

    public function test_skips_events_from_same_service()
    {
        $handler = \Mockery::mock();
        $handler->shouldNotReceive('__invoke');

        $this->eventSubscriber->registerHandler('employee.created', $handler);

        // Simulate event from same service
        $eventData = json_encode([
            'event_name' => 'employee.created',
            'payload' => ['id' => 'test-id'],
            'metadata' => ['service' => 'test-service'],
        ]);

        $this->eventSubscriber->handleEvent($eventData);

        $this->assertTrue(true); // If we get here without exception, test passes
    }

    public function test_processes_events_from_other_services()
    {
        $handler = \Mockery::mock();
        $handler->shouldReceive('__invoke')
            ->once()
            ->with(['id' => 'test-id'], ['service' => 'other-service']);

        $this->eventSubscriber->registerHandler('employee.created', $handler);

        // Simulate event from other service
        $eventData = json_encode([
            'event_name' => 'employee.created',
            'payload' => ['id' => 'test-id'],
            'metadata' => ['service' => 'other-service'],
        ]);

        $this->eventSubscriber->handleEvent($eventData);

        $this->assertTrue(true); // If we get here without exception, test passes
    }

    public function test_skips_events_without_handler()
    {
        Log::shouldReceive('debug')
            ->once()
            ->with('No handler for event', \Mockery::type('array'));

        // Don't register any handlers

        // Simulate event without handler
        $eventData = json_encode([
            'event_name' => 'unknown.event',
            'payload' => ['id' => 'test-id'],
            'metadata' => ['service' => 'other-service'],
        ]);

        $this->eventSubscriber->handleEvent($eventData);

        $this->assertTrue(true); // If we get here without exception, test passes
    }

    public function test_handles_handler_errors_gracefully()
    {
        Log::shouldReceive('error')
            ->once()
            ->with('Failed to handle event', \Mockery::type('array'));

        $handler = function ($payload, $metadata) {
            throw new \Exception('Handler error');
        };

        $this->eventSubscriber->registerHandler('employee.created', $handler);

        // Simulate event that will cause handler error
        $eventData = json_encode([
            'event_name' => 'employee.created',
            'payload' => ['id' => 'test-id'],
            'metadata' => ['service' => 'other-service'],
        ]);

        $this->eventSubscriber->handleEvent($eventData);

        $this->assertTrue(true); // If we get here without exception, test passes
    }

    public function test_logs_successful_event_processing()
    {
        Log::shouldReceive('info')
            ->once()
            ->with('Processing event', \Mockery::type('array'));

        $handler = function ($payload, $metadata) {
            return 'handled';
        };

        $this->eventSubscriber->registerHandler('employee.created', $handler);

        // Simulate event processing
        $eventData = json_encode([
            'event_name' => 'employee.created',
            'payload' => ['id' => 'test-id'],
            'metadata' => ['service' => 'other-service', 'event_id' => 'test-event-id'],
        ]);

        $this->eventSubscriber->handleEvent($eventData);

        $this->assertTrue(true); // If we get here without exception, test passes
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}
