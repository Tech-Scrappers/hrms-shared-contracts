<?php

namespace Shared\Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

abstract class BaseTestCase extends BaseTestCase
{
    use RefreshDatabase;
    
    protected string $tenantId;
    protected array $testTenant;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Clear Redis cache
        Redis::flushall();
        
        // Set up test tenant
        $this->tenantId = 'test-tenant-' . uniqid();
        $this->testTenant = [
            'id' => $this->tenantId,
            'name' => 'Test Tenant',
            'domain' => 'test.example.com',
            'database_name' => 'tenant_' . $this->tenantId,
            'is_active' => true,
        ];
    }
    
    protected function tearDown(): void
    {
        // Clean up test data
        Redis::flushall();
        
        parent::tearDown();
    }
    
    protected function createTestTenant(): array
    {
        return $this->testTenant;
    }
    
    protected function assertApiResponseStructure(array $response): void
    {
        $this->assertArrayHasKey('success', $response);
        $this->assertArrayHasKey('message', $response);
        $this->assertArrayHasKey('timestamp', $response);
        $this->assertArrayHasKey('code', $response);
    }
    
    protected function assertSuccessResponse(array $response, int $expectedCode = 200): void
    {
        $this->assertApiResponseStructure($response);
        $this->assertTrue($response['success']);
        $this->assertEquals($expectedCode, $response['code']);
    }
    
    protected function assertErrorResponse(array $response, int $expectedCode = 400): void
    {
        $this->assertApiResponseStructure($response);
        $this->assertFalse($response['success']);
        $this->assertEquals($expectedCode, $response['code']);
    }
    
    protected function assertValidationError(array $response): void
    {
        $this->assertErrorResponse($response, 422);
        $this->assertArrayHasKey('errors', $response);
    }
}
