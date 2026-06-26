<?php

declare(strict_types=1);

namespace livehand\abtestcraft\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\db\Query;
use craft\helpers\StringHelper;
use livehand\abtestcraft\models\Goal;
use livehand\abtestcraft\models\Settings;
use livehand\abtestcraft\models\Test;
use livehand\abtestcraft\records\ConversionRecord;
use livehand\abtestcraft\records\DailyStatsRecord;
use livehand\abtestcraft\records\GoalRecord;
use livehand\abtestcraft\records\TestRecord;
use livehand\abtestcraft\records\VisitorRecord;
use livehand\abtestcraft\services\TrackingService;
use livehand\abtestcraft\ABTestCraft;
use DateTime;

/**
 * Integration tests for TrackingService
 *
 * These tests verify impression and conversion recording with real database operations.
 */
class TrackingServiceIntegrationTest extends Unit
{
    private TrackingService $service;
    private ?int $testRecordId = null;

    protected function _before(): void
    {
        $this->service = ABTestCraft::getInstance()->tracking;
        $this->cleanupTestData();
    }

    protected function _after(): void
    {
        $this->cleanupTestData();
    }

    /**
     * Clean up test data
     */
    private function cleanupTestData(): void
    {
        if ($this->testRecordId) {
            DailyStatsRecord::deleteAll(['testId' => $this->testRecordId]);
            ConversionRecord::deleteAll(['testId' => $this->testRecordId]);
            VisitorRecord::deleteAll(['testId' => $this->testRecordId]);
            GoalRecord::deleteAll(['testId' => $this->testRecordId]);
            TestRecord::deleteAll(['id' => $this->testRecordId]);
            $this->testRecordId = null;
        }

        // Also clean up any test records created by handle
        TestRecord::deleteAll(['handle' => 'tracking-test']);
    }

    /**
     * Get valid element IDs from the database for FK constraints
     * Uses any element type since FK references the elements table.
     * Creates dummy elements if not enough exist.
     */
    private function getValidEntryIds(): array
    {
        $elements = (new Query())
            ->select(['id'])
            ->from('{{%elements}}')
            ->limit(2)
            ->column();

        // If we don't have 2 elements, create dummy ones for testing
        while (count($elements) < 2) {
            $id = $this->createDummyElement();
            if ($id) {
                $elements[] = $id;
            } else {
                break;
            }
        }

        if (count($elements) < 2) {
            $this->markTestSkipped('Need at least 2 elements in the database for integration tests');
        }

        return [(int)$elements[0], (int)$elements[1]];
    }

    /**
     * Create a dummy element for FK constraint testing
     */
    private function createDummyElement(): ?int
    {
        $uid = StringHelper::UUID();
        $now = (new DateTime())->format('Y-m-d H:i:s');

        Craft::$app->getDb()->createCommand()->insert('{{%elements}}', [
            'type' => 'craft\\elements\\Entry',
            'enabled' => true,
            'archived' => false,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => $uid,
        ])->execute();

        return (int) Craft::$app->getDb()->getLastInsertID();
    }

    /**
     * Create a test record for testing
     */
    private function createTestRecord(array $attributes = []): TestRecord
    {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        [$controlEntryId, $variantEntryId] = $this->getValidEntryIds();

        $record = new TestRecord();
        $record->siteId = $siteId;
        $record->name = $attributes['name'] ?? 'Tracking Test';
        $record->handle = $attributes['handle'] ?? 'tracking-test';
        $record->status = $attributes['status'] ?? Test::STATUS_RUNNING;
        $record->controlEntryId = $attributes['controlEntryId'] ?? $controlEntryId;
        $record->variantEntryId = $attributes['variantEntryId'] ?? $variantEntryId;
        $record->trafficSplit = $attributes['trafficSplit'] ?? 50;
        $record->goalType = $attributes['goalType'] ?? Goal::TYPE_PAGE;
        $record->save(false);

        $this->testRecordId = $record->id;

        return $record;
    }

    /**
     * Create a Test model from a TestRecord
     */
    private function createTestModel(TestRecord $record): Test
    {
        $test = new Test();
        $test->id = $record->id;
        $test->siteId = $record->siteId;
        $test->name = $record->name;
        $test->handle = $record->handle;
        $test->status = $record->status;
        $test->controlEntryId = $record->controlEntryId;
        $test->variantEntryId = $record->variantEntryId;
        $test->trafficSplit = $record->trafficSplit;
        $test->goalType = $record->goalType;

        return $test;
    }

    /**
     * Create a visitor record for testing conversions
     */
    private function createVisitorRecord(int $testId, string $variant, string $visitorId): VisitorRecord
    {
        $record = new VisitorRecord();
        $record->testId = $testId;
        $record->visitorId = $visitorId;
        $record->variant = $variant;
        $record->converted = false;
        $record->save(false);

        return $record;
    }

    /**
     * Create a conversion event record for testing converted visitor counts.
     */
    private function createConversionRecord(int $testId, string $variant, string $visitorId, string $conversionType): ConversionRecord
    {
        $record = new ConversionRecord();
        $record->testId = $testId;
        $record->visitorId = $visitorId;
        $record->variant = $variant;
        $record->conversionType = $conversionType;
        $record->dateConverted = (new DateTime())->format('Y-m-d H:i:s');
        $record->save(false);

        return $record;
    }

    /**
     * Create an enabled goal record for testing conversions.
     */
    private function createGoalRecord(int $testId, string $goalType): GoalRecord
    {
        $record = new GoalRecord();
        $record->testId = $testId;
        $record->goalType = $goalType;
        $record->isEnabled = true;
        $record->sortOrder = 0;
        $record->save(false);

        return $record;
    }

    // ========================================
    // Impression Recording Tests
    // ========================================

    /**
     * Test that recording an impression creates a new DailyStatsRecord
     */
    public function testRecordImpressionCreatesNewRecord(): void
    {
        $record = $this->createTestRecord();
        $test = $this->createTestModel($record);

        // Record impression for control variant
        $result = $this->service->recordImpression($test, Test::VARIANT_CONTROL);

        $this->assertTrue($result, 'recordImpression should return true');

        // Verify record was created
        $statsRecord = DailyStatsRecord::findOne([
            'testId' => $test->id,
            'variant' => Test::VARIANT_CONTROL,
        ]);

        $this->assertNotNull($statsRecord, 'DailyStatsRecord should be created');
        $this->assertEquals(1, $statsRecord->impressions, 'Impressions should be 1');
        $this->assertEquals(0, $statsRecord->conversions, 'Conversions should be 0');
        $this->assertEquals((new DateTime())->format('Y-m-d'), $statsRecord->date, 'Date should be today');
    }

    /**
     * Test that subsequent impressions increment the counter
     */
    public function testRecordImpressionIncrementsExisting(): void
    {
        $record = $this->createTestRecord();
        $test = $this->createTestModel($record);

        // Record multiple impressions
        $this->service->recordImpression($test, Test::VARIANT_CONTROL);
        $this->service->recordImpression($test, Test::VARIANT_CONTROL);
        $this->service->recordImpression($test, Test::VARIANT_CONTROL);

        // Verify counter was incremented
        $statsRecord = DailyStatsRecord::findOne([
            'testId' => $test->id,
            'variant' => Test::VARIANT_CONTROL,
        ]);

        $this->assertEquals(3, $statsRecord->impressions, 'Impressions should be 3 after 3 calls');
    }

    /**
     * Test that impressions are tracked separately per variant
     */
    public function testRecordImpressionTracksVariantsSeparately(): void
    {
        $record = $this->createTestRecord();
        $test = $this->createTestModel($record);

        // Record impressions for both variants
        $this->service->recordImpression($test, Test::VARIANT_CONTROL);
        $this->service->recordImpression($test, Test::VARIANT_CONTROL);
        $this->service->recordImpression($test, Test::VARIANT_VARIANT);

        // Verify separate tracking
        $controlStats = DailyStatsRecord::findOne([
            'testId' => $test->id,
            'variant' => Test::VARIANT_CONTROL,
        ]);

        $variantStats = DailyStatsRecord::findOne([
            'testId' => $test->id,
            'variant' => Test::VARIANT_VARIANT,
        ]);

        $this->assertEquals(2, $controlStats->impressions, 'Control should have 2 impressions');
        $this->assertEquals(1, $variantStats->impressions, 'Variant should have 1 impression');
    }

    // ========================================
    // Total Impressions/Conversions Tests
    // ========================================

    /**
     * Test getTotalImpressions returns correct values
     */
    public function testGetTotalImpressions(): void
    {
        $record = $this->createTestRecord();
        $test = $this->createTestModel($record);

        // Create stats records directly
        $today = (new DateTime())->format('Y-m-d');

        $controlStats = new DailyStatsRecord();
        $controlStats->testId = $test->id;
        $controlStats->date = $today;
        $controlStats->variant = Test::VARIANT_CONTROL;
        $controlStats->impressions = 100;
        $controlStats->conversions = 10;
        $controlStats->save(false);

        $variantStats = new DailyStatsRecord();
        $variantStats->testId = $test->id;
        $variantStats->date = $today;
        $variantStats->variant = Test::VARIANT_VARIANT;
        $variantStats->impressions = 150;
        $variantStats->conversions = 20;
        $variantStats->save(false);

        // Get totals
        $impressions = $this->service->getTotalImpressions($test);

        $this->assertEquals(100, $impressions['control'], 'Control impressions should be 100');
        $this->assertEquals(150, $impressions['variant'], 'Variant impressions should be 150');
    }

    /**
     * Test getTotalConversions returns correct values (excludes goal-specific)
     */
    public function testGetTotalConversions(): void
    {
        $record = $this->createTestRecord();
        $test = $this->createTestModel($record);

        $today = (new DateTime())->format('Y-m-d');

        // Create general stats (goalType = null)
        $generalStats = new DailyStatsRecord();
        $generalStats->testId = $test->id;
        $generalStats->date = $today;
        $generalStats->variant = Test::VARIANT_CONTROL;
        $generalStats->impressions = 100;
        $generalStats->conversions = 10;
        $generalStats->goalType = null;
        $generalStats->save(false);

        // Create goal-specific stats (should be excluded)
        $goalStats = new DailyStatsRecord();
        $goalStats->testId = $test->id;
        $goalStats->date = $today;
        $goalStats->variant = Test::VARIANT_CONTROL;
        $goalStats->impressions = 0;
        $goalStats->conversions = 5;
        $goalStats->goalType = 'form';
        $goalStats->save(false);

        // Get totals - should only include general stats
        $conversions = $this->service->getTotalConversions($test);

        $this->assertEquals(10, $conversions['control'], 'Control conversions should be 10 (general only)');
    }

    /**
     * Test getUniqueVisitors returns correct counts
     */
    public function testGetUniqueVisitors(): void
    {
        $record = $this->createTestRecord();
        $test = $this->createTestModel($record);

        // Create visitor records
        $this->createVisitorRecord($test->id, Test::VARIANT_CONTROL, 'visitor-1');
        $this->createVisitorRecord($test->id, Test::VARIANT_CONTROL, 'visitor-2');
        $this->createVisitorRecord($test->id, Test::VARIANT_VARIANT, 'visitor-3');

        // Get unique visitors
        $visitors = $this->service->getUniqueVisitors($test);

        $this->assertEquals(2, $visitors['control'], 'Control should have 2 unique visitors');
        $this->assertEquals(1, $visitors['variant'], 'Variant should have 1 unique visitor');
    }

    /**
     * Test getConvertedVisitors counts each visitor once even with multiple conversions.
     */
    public function testGetConvertedVisitorsCountsDistinctVisitors(): void
    {
        $record = $this->createTestRecord();
        $test = $this->createTestModel($record);

        $this->createConversionRecord($test->id, Test::VARIANT_CONTROL, 'visitor-1', 'form');
        $this->createConversionRecord($test->id, Test::VARIANT_CONTROL, 'visitor-1', 'email');
        $this->createConversionRecord($test->id, Test::VARIANT_VARIANT, 'visitor-2', 'form');

        $visitors = $this->service->getConvertedVisitors($test);

        $this->assertEquals(1, $visitors['control'], 'Control should count visitor-1 once');
        $this->assertEquals(1, $visitors['variant'], 'Variant should count visitor-2 once');
    }

    /**
     * Test conversion uses the test's enabled goal type even if client goalId is stale.
     */
    public function testRecordConversionFallsBackToEnabledGoalByType(): void
    {
        $record = $this->createTestRecord();
        $test = $this->createTestModel($record);
        $goal = $this->createGoalRecord($test->id, Goal::TYPE_FORM);
        $visitorId = StringHelper::UUID();
        $this->createVisitorRecord($test->id, Test::VARIANT_CONTROL, $visitorId);

        $_COOKIE['_abtestcraft_vid'] = $visitorId;

        $result = $this->service->recordConversion($test, Goal::TYPE_FORM, 999999);

        $this->assertTrue($result, 'Valid enabled goal type should count even with stale goalId');

        $conversion = ConversionRecord::findOne([
            'testId' => $test->id,
            'visitorId' => $visitorId,
            'conversionType' => Goal::TYPE_FORM,
        ]);

        $this->assertNotNull($conversion);
        $this->assertEquals($goal->id, $conversion->goalId);
    }

    /**
     * Test that empty test returns zeros
     */
    public function testGetTotalImpressionsReturnsZerosForEmptyTest(): void
    {
        $record = $this->createTestRecord();
        $test = $this->createTestModel($record);

        $impressions = $this->service->getTotalImpressions($test);

        $this->assertEquals(0, $impressions['control'], 'Control should be 0');
        $this->assertEquals(0, $impressions['variant'], 'Variant should be 0');
    }

    // ========================================
    // Record by Handle Tests
    // ========================================

    /**
     * Test recordConversionByHandle with non-existent handle
     */
    public function testRecordConversionByHandleWithInvalidHandle(): void
    {
        $result = $this->service->recordConversionByHandle('non-existent-test', 'form');

        $this->assertFalse($result, 'Should return false for non-existent handle');
    }

    /**
     * Test recordConversionByHandle with non-running test
     */
    public function testRecordConversionByHandleWithNonRunningTest(): void
    {
        $record = $this->createTestRecord(['status' => Test::STATUS_DRAFT]);

        $result = $this->service->recordConversionByHandle('tracking-test', 'form');

        $this->assertFalse($result, 'Should return false for non-running test');
    }

    // ========================================
    // Multi-day Aggregation Tests
    // ========================================

    /**
     * Test that impressions are aggregated across multiple days
     */
    public function testImpressionsAggregateAcrossDays(): void
    {
        $record = $this->createTestRecord();
        $test = $this->createTestModel($record);

        // Create stats for multiple days
        $statsDay1 = new DailyStatsRecord();
        $statsDay1->testId = $test->id;
        $statsDay1->date = '2025-01-01';
        $statsDay1->variant = Test::VARIANT_CONTROL;
        $statsDay1->impressions = 50;
        $statsDay1->conversions = 5;
        $statsDay1->save(false);

        $statsDay2 = new DailyStatsRecord();
        $statsDay2->testId = $test->id;
        $statsDay2->date = '2025-01-02';
        $statsDay2->variant = Test::VARIANT_CONTROL;
        $statsDay2->impressions = 75;
        $statsDay2->conversions = 8;
        $statsDay2->save(false);

        // Get totals - should aggregate
        $impressions = $this->service->getTotalImpressions($test);

        $this->assertEquals(125, $impressions['control'], 'Control should aggregate to 125');
    }
}
