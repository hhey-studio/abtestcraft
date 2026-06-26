<?php

declare(strict_types=1);

namespace livehand\abtestcraft\tests\unit;

use Codeception\Test\Unit;
use livehand\abtestcraft\models\Test;

/**
 * Unit tests for Test model
 *
 * Tests model validation, status helpers, and business logic.
 */
class TestModelTest extends Unit
{
    /**
     * Test status constants are defined
     */
    public function testStatusConstantsExist(): void
    {
        $this->assertEquals('draft', Test::STATUS_DRAFT);
        $this->assertEquals('running', Test::STATUS_RUNNING);
        $this->assertEquals('paused', Test::STATUS_PAUSED);
        $this->assertEquals('completed', Test::STATUS_COMPLETED);
    }

    /**
     * Test variant constants are defined
     */
    public function testVariantConstantsExist(): void
    {
        $this->assertEquals('control', Test::VARIANT_CONTROL);
        $this->assertEquals('variant', Test::VARIANT_VARIANT);
    }

    /**
     * Test goal type constants are defined
     */
    public function testGoalTypeConstantsExist(): void
    {
        $this->assertEquals('phone', Test::GOAL_PHONE);
        $this->assertEquals('form', Test::GOAL_FORM);
        $this->assertEquals('page', Test::GOAL_PAGE);
        $this->assertEquals('email', Test::GOAL_EMAIL);
        $this->assertEquals('download', Test::GOAL_DOWNLOAD);
    }

    /**
     * Test isRunning helper
     */
    public function testIsRunning(): void
    {
        $test = new Test();

        $test->status = Test::STATUS_DRAFT;
        $this->assertFalse($test->isRunning());

        $test->status = Test::STATUS_RUNNING;
        $this->assertTrue($test->isRunning());

        $test->status = Test::STATUS_PAUSED;
        $this->assertFalse($test->isRunning());

        $test->status = Test::STATUS_COMPLETED;
        $this->assertFalse($test->isRunning());
    }

    /**
     * Test isCompleted helper
     */
    public function testIsCompleted(): void
    {
        $test = new Test();

        $test->status = Test::STATUS_DRAFT;
        $this->assertFalse($test->isCompleted());

        $test->status = Test::STATUS_RUNNING;
        $this->assertFalse($test->isCompleted());

        $test->status = Test::STATUS_PAUSED;
        $this->assertFalse($test->isCompleted());

        $test->status = Test::STATUS_COMPLETED;
        $this->assertTrue($test->isCompleted());
    }

    /**
     * Test isTrashed helper
     */
    public function testIsTrashed(): void
    {
        $test = new Test();

        // Not trashed by default
        $this->assertFalse($test->isTrashed());

        // Set dateDeleted
        $test->dateDeleted = new \DateTime();
        $this->assertTrue($test->isTrashed());
    }

    /**
     * Test canStart helper
     */
    public function testCanStart(): void
    {
        $test = new Test();

        $test->status = Test::STATUS_DRAFT;
        $this->assertTrue($test->canStart());

        $test->status = Test::STATUS_PAUSED;
        $this->assertTrue($test->canStart());

        $test->status = Test::STATUS_RUNNING;
        $this->assertFalse($test->canStart());

        $test->status = Test::STATUS_COMPLETED;
        $this->assertFalse($test->canStart());
    }

    /**
     * Test canPause helper
     */
    public function testCanPause(): void
    {
        $test = new Test();

        $test->status = Test::STATUS_DRAFT;
        $this->assertFalse($test->canPause());

        $test->status = Test::STATUS_RUNNING;
        $this->assertTrue($test->canPause());

        $test->status = Test::STATUS_PAUSED;
        $this->assertFalse($test->canPause());

        $test->status = Test::STATUS_COMPLETED;
        $this->assertFalse($test->canPause());
    }

    /**
     * Test getDurationDays with no start date
     */
    public function testGetDurationDaysWithNoStart(): void
    {
        $test = new Test();
        $this->assertNull($test->getDurationDays());
    }

    /**
     * Test getDurationDays for running test
     */
    public function testGetDurationDaysForRunningTest(): void
    {
        $test = new Test();
        $test->startedAt = new \DateTime('-5 days');
        $test->status = Test::STATUS_RUNNING;

        $days = $test->getDurationDays();
        $this->assertGreaterThanOrEqual(5, $days);
    }

    /**
     * Test getDurationDays for completed test
     */
    public function testGetDurationDaysForCompletedTest(): void
    {
        $test = new Test();
        $test->startedAt = new \DateTime('2024-01-01');
        $test->endedAt = new \DateTime('2024-01-11');
        $test->status = Test::STATUS_COMPLETED;

        $days = $test->getDurationDays();
        $this->assertEquals(10, $days);
    }

    /**
     * Test getDurationDays returns minimum 1 day
     */
    public function testGetDurationDaysMinimumOneDay(): void
    {
        $test = new Test();
        $test->startedAt = new \DateTime();
        $test->endedAt = new \DateTime();
        $test->status = Test::STATUS_COMPLETED;

        $days = $test->getDurationDays();
        $this->assertEquals(1, $days);
    }

    /**
     * Test generateHandle from name
     */
    public function testGenerateHandle(): void
    {
        $test = new Test();
        $test->name = 'My Test Name';
        $test->generateHandle();

        $this->assertEquals('myTestName', $test->handle);
    }

    /**
     * Test generateHandle doesn't override existing handle
     */
    public function testGenerateHandlePreservesExisting(): void
    {
        $test = new Test();
        $test->name = 'My Test Name';
        $test->handle = 'custom-handle';
        $test->generateHandle();

        $this->assertEquals('custom-handle', $test->handle);
    }

    /**
     * Test getStatuses returns all statuses
     */
    public function testGetStatuses(): void
    {
        $statuses = Test::getStatuses();

        $this->assertIsArray($statuses);
        $this->assertArrayHasKey(Test::STATUS_DRAFT, $statuses);
        $this->assertArrayHasKey(Test::STATUS_RUNNING, $statuses);
        $this->assertArrayHasKey(Test::STATUS_PAUSED, $statuses);
        $this->assertArrayHasKey(Test::STATUS_COMPLETED, $statuses);
    }

    /**
     * Test getGoalTypes returns all goal types
     */
    public function testGetGoalTypes(): void
    {
        $types = Test::getGoalTypes();

        $this->assertIsArray($types);
        $this->assertArrayHasKey(Test::GOAL_PHONE, $types);
        $this->assertArrayHasKey(Test::GOAL_FORM, $types);
        $this->assertArrayHasKey(Test::GOAL_PAGE, $types);
        $this->assertArrayHasKey(Test::GOAL_EMAIL, $types);
        $this->assertArrayHasKey(Test::GOAL_DOWNLOAD, $types);
    }

    /**
     * Test validation requires essential fields
     */
    public function testValidationRequiredFields(): void
    {
        $test = new Test();
        $valid = $test->validate();

        $this->assertFalse($valid);
        $this->assertTrue($test->hasErrors('name'));
        $this->assertTrue($test->hasErrors('handle'));
        $this->assertTrue($test->hasErrors('controlEntryId'));
        $this->assertTrue($test->hasErrors('variantEntryId'));
    }

    /**
     * Test handle format validation
     */
    public function testHandleFormatValidation(): void
    {
        $test = new Test();
        $test->name = 'Test';
        $test->controlEntryId = 1;
        $test->variantEntryId = 2;
        $test->goalType = Test::GOAL_FORM;

        // Valid handles
        $test->handle = 'myTest';
        $this->assertTrue($test->validate(['handle']));

        $test->handle = 'test123';
        $this->assertTrue($test->validate(['handle']));

        $test->handle = 'a';
        $this->assertTrue($test->validate(['handle']));

        // Invalid handles
        $test->handle = 'My-Test'; // Hyphen
        $this->assertFalse($test->validate(['handle']));

        $test->handle = '123test'; // Starts with number
        $this->assertFalse($test->validate(['handle']));

        $test->handle = 'test_name'; // Underscore is valid for Craft handles
        $this->assertTrue($test->validate(['handle']));
    }

    /**
     * Test traffic split validation
     */
    public function testTrafficSplitValidation(): void
    {
        $test = new Test();
        $test->name = 'Test';
        $test->handle = 'test';
        $test->controlEntryId = 1;
        $test->variantEntryId = 2;
        $test->goalType = Test::GOAL_FORM;

        // Valid values
        $test->trafficSplit = 0;
        $this->assertTrue($test->validate(['trafficSplit']));

        $test->trafficSplit = 50;
        $this->assertTrue($test->validate(['trafficSplit']));

        $test->trafficSplit = 100;
        $this->assertTrue($test->validate(['trafficSplit']));

        // Invalid values
        $test->trafficSplit = -1;
        $this->assertFalse($test->validate(['trafficSplit']));

        $test->trafficSplit = 101;
        $this->assertFalse($test->validate(['trafficSplit']));
    }

    /**
     * Test status validation
     */
    public function testStatusValidation(): void
    {
        $test = new Test();
        $test->name = 'Test';
        $test->handle = 'test';
        $test->controlEntryId = 1;
        $test->variantEntryId = 2;
        $test->goalType = Test::GOAL_FORM;

        // Valid statuses
        foreach ([Test::STATUS_DRAFT, Test::STATUS_RUNNING, Test::STATUS_PAUSED, Test::STATUS_COMPLETED] as $status) {
            $test->status = $status;
            $this->assertTrue($test->validate(['status']), "Status '{$status}' should be valid");
        }

        // Invalid status
        $test->status = 'invalid';
        $this->assertFalse($test->validate(['status']));
    }

    /**
     * Test winner variant validation
     */
    public function testWinnerVariantValidation(): void
    {
        $test = new Test();
        $test->name = 'Test';
        $test->handle = 'test';
        $test->controlEntryId = 1;
        $test->variantEntryId = 2;
        $test->goalType = Test::GOAL_FORM;

        // Valid values
        $test->winnerVariant = Test::VARIANT_CONTROL;
        $this->assertTrue($test->validate(['winnerVariant']));

        $test->winnerVariant = Test::VARIANT_VARIANT;
        $this->assertTrue($test->validate(['winnerVariant']));

        $test->winnerVariant = null;
        $this->assertTrue($test->validate(['winnerVariant']));

        // Invalid value
        $test->winnerVariant = 'invalid';
        $this->assertFalse($test->validate(['winnerVariant']));
    }
}
