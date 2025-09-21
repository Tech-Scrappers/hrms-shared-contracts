<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\BaseTestCase;

class BaseTestCaseTest extends TestCase
{
    public function test_base_test_case_has_required_methods()
    {
        $reflection = new \ReflectionClass(BaseTestCase::class);

        $this->assertTrue($reflection->hasMethod('createTestTenant'));
        $this->assertTrue($reflection->hasMethod('assertApiResponseStructure'));
        $this->assertTrue($reflection->hasMethod('assertSuccessResponse'));
        $this->assertTrue($reflection->hasMethod('assertErrorResponse'));
        $this->assertTrue($reflection->hasMethod('assertValidationError'));
    }

    public function test_base_test_case_extends_phpunit_testcase()
    {
        $reflection = new \ReflectionClass(BaseTestCase::class);
        $parent = $reflection->getParentClass();

        $this->assertNotNull($parent);
        $this->assertEquals(\PHPUnit\Framework\TestCase::class, $parent->getName());
    }

    public function test_can_instantiate_base_test_case()
    {
        $this->assertTrue(class_exists(BaseTestCase::class));
        $this->assertTrue(is_subclass_of(BaseTestCase::class, \PHPUnit\Framework\TestCase::class));
    }
}
