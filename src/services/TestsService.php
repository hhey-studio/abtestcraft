<?php

declare(strict_types=1);

namespace livehand\abtestcraft\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use livehand\abtestcraft\models\Test;
use livehand\abtestcraft\records\TestRecord;
use livehand\abtestcraft\ABTestCraft;
use DateTime;

/**
 * Tests service - CRUD operations for split tests
 */
class TestsService extends Component
{
    /**
     * Get all tests with optional pagination
     *
     * @param int|null $siteId Filter by site ID
     * @param int $limit Maximum number of tests to return (0 for unlimited)
     * @param int $offset Number of tests to skip
     * @param bool $includeTrashed Include soft-deleted tests
     * @return Test[]
     */
    public function getAllTests(int|string|null $siteId = null, int $limit = 0, int $offset = 0, bool $includeTrashed = false): array
    {
        $query = TestRecord::find();

        if ($siteId) {
            $query->where(['siteId' => $siteId]);
        }

        if (!$includeTrashed) {
            $query->andWhere(['dateDeleted' => null]);
        }

        $query->orderBy(['dateCreated' => SORT_DESC]);

        if ($limit > 0) {
            $query->limit($limit)->offset($offset);
        }

        return array_map(fn($record) => $this->createModelFromRecord($record), $query->all());
    }

    /**
     * Get total count of tests
     *
     * @param int|null $siteId Filter by site ID
     * @param bool $includeTrashed Include soft-deleted tests
     * @return int Total number of tests
     */
    public function getTotalTestCount(int|string|null $siteId = null, bool $includeTrashed = false): int
    {
        $query = (new Query())->from('{{%abtestcraft_tests}}');

        if ($siteId) {
            $query->where(['siteId' => $siteId]);
        }

        if (!$includeTrashed) {
            $query->andWhere(['dateDeleted' => null]);
        }

        return (int) $query->count();
    }

    /**
     * Get active tests (running)
     */
    public function getActiveTests(int|string|null $siteId = null): array
    {
        $query = TestRecord::find()
            ->where(['status' => Test::STATUS_RUNNING])
            ->andWhere(['dateDeleted' => null]);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        return array_map(fn($record) => $this->createModelFromRecord($record), $query->all());
    }

    /**
     * Get tests filtered by status category
     *
     * @param string $filter 'active' (draft/running/paused), 'completed', 'trashed', or 'all'
     */
    public function getTestsByStatus(string $filter = 'active', int|string|null $siteId = null): array
    {
        $query = TestRecord::find();

        if ($siteId) {
            $query->where(['siteId' => $siteId]);
        }

        if ($filter === 'trashed') {
            $query->andWhere(['not', ['dateDeleted' => null]]);
        } else {
            // Exclude trashed for all other filters
            $query->andWhere(['dateDeleted' => null]);

            if ($filter === 'active') {
                $query->andWhere(['in', 'status', [Test::STATUS_DRAFT, Test::STATUS_RUNNING, Test::STATUS_PAUSED]]);
            } elseif ($filter === 'completed') {
                $query->andWhere(['status' => Test::STATUS_COMPLETED]);
            }
            // 'all' applies no status filter (but still excludes trashed)
        }

        $query->orderBy(['dateCreated' => SORT_DESC]);

        return array_map(fn($record) => $this->createModelFromRecord($record), $query->all());
    }

    /**
     * Get test counts by status category
     *
     * @return array{active: int, completed: int, trashed: int, all: int}
     */
    public function getTestCounts(int|string|null $siteId = null): array
    {
        $baseQuery = (new Query())->from('{{%abtestcraft_tests}}');

        if ($siteId) {
            $baseQuery->where(['siteId' => $siteId]);
        }

        // Non-trashed query base
        $notTrashedQuery = (clone $baseQuery)->andWhere(['dateDeleted' => null]);

        $activeCount = (clone $notTrashedQuery)
            ->andWhere(['in', 'status', [Test::STATUS_DRAFT, Test::STATUS_RUNNING, Test::STATUS_PAUSED]])
            ->count();

        $completedCount = (clone $notTrashedQuery)
            ->andWhere(['status' => Test::STATUS_COMPLETED])
            ->count();

        $trashedCount = (clone $baseQuery)
            ->andWhere(['not', ['dateDeleted' => null]])
            ->count();

        return [
            'active' => (int) $activeCount,
            'completed' => (int) $completedCount,
            'trashed' => (int) $trashedCount,
            'all' => (int) $activeCount + (int) $completedCount,
        ];
    }

    /**
     * Get a test by ID
     */
    public function getTestById(int|string $id): ?Test
    {
        $record = TestRecord::findOne((int) $id);

        if (!$record) {
            return null;
        }

        return $this->createModelFromRecord($record);
    }

    /**
     * Get a test by handle
     */
    public function getTestByHandle(string $handle): ?Test
    {
        $record = TestRecord::findOne(['handle' => $handle]);

        if (!$record) {
            return null;
        }

        return $this->createModelFromRecord($record);
    }

    /**
     * Get test by control entry ID
     */
    public function getTestByControlEntryId(int|string $entryId, int|string|null $siteId = null): ?Test
    {
        $query = TestRecord::find()
            ->where(['controlEntryId' => (int) $entryId])
            ->andWhere(['status' => Test::STATUS_RUNNING]);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $record = $query->one();

        if (!$record) {
            return null;
        }

        return $this->createModelFromRecord($record);
    }

    /**
     * Save a test
     */
    public function saveTest(Test $test): bool
    {
        $test->generateHandle();

        if (!$test->validate()) {
            return false;
        }

        if ($test->id) {
            $record = TestRecord::findOne($test->id);
            if (!$record) {
                return false;
            }
        } else {
            $record = new TestRecord();
        }

        $record->siteId = $test->siteId ?? Craft::$app->getSites()->getCurrentSite()->id;
        $record->name = $test->name;
        $record->handle = $test->handle;
        $record->hypothesis = $test->hypothesis;
        $record->variantDescription = $test->variantDescription;
        $record->learnings = $test->learnings;
        $record->status = $test->status;
        $record->controlEntryId = $test->controlEntryId;
        $record->variantEntryId = $test->variantEntryId;
        $record->trafficSplit = $test->trafficSplit;
        $record->goalType = $test->goalType;
        $record->goalValue = $test->goalValue;
        $record->startedAt = $test->startedAt;
        $record->endedAt = $test->endedAt;
        $record->winnerVariant = $test->winnerVariant;

        if (!$record->save()) {
            $test->addErrors($record->getErrors());
            return false;
        }

        $test->id = $record->id;
        $test->dateCreated = new DateTime($record->dateCreated);
        $test->dateUpdated = new DateTime($record->dateUpdated);
        $test->uid = $record->uid;

        ABTestCraft::getInstance()->stats->invalidateStatsCache((int) $test->id);

        // Rebuild cascade descendant mappings for this test
        if ($test->controlEntryId && $test->variantEntryId) {
            ABTestCraft::getInstance()->cascade->rebuildDescendants($test);
        }

        return true;
    }

    /**
     * Soft delete a test (move to trash)
     */
    public function deleteTest(Test $test): bool
    {
        if (!$test->id) {
            return false;
        }

        $record = TestRecord::findOne($test->id);

        if (!$record) {
            return false;
        }

        // Soft delete by setting dateDeleted
        $record->dateDeleted = (new DateTime())->format('Y-m-d H:i:s');

        if (!$record->save()) {
            return false;
        }

        $test->dateDeleted = new DateTime($record->dateDeleted);

        Craft::info("Split test '{$test->handle}' (ID: {$test->id}) moved to trash", 'abtestcraft');

        // Audit log
        ABTestCraft::getInstance()->audit->logTestTrashed($test);

        return true;
    }

    /**
     * Restore a soft-deleted test from trash
     */
    public function restoreTest(Test $test): bool
    {
        if (!$test->id || !$test->isTrashed()) {
            return false;
        }

        $record = TestRecord::findOne($test->id);

        if (!$record) {
            return false;
        }

        $record->dateDeleted = null;

        if (!$record->save()) {
            return false;
        }

        $test->dateDeleted = null;

        Craft::info("Split test '{$test->handle}' (ID: {$test->id}) restored from trash", 'abtestcraft');

        // Audit log
        ABTestCraft::getInstance()->audit->logTestRestored($test);

        return true;
    }

    /**
     * Permanently delete a test (hard delete)
     */
    public function hardDeleteTest(Test $test): bool
    {
        if (!$test->id) {
            return false;
        }

        $record = TestRecord::findOne($test->id);

        if (!$record) {
            return false;
        }

        $result = (bool) $record->delete();

        if ($result) {
            Craft::info("Split test '{$test->handle}' (ID: {$test->id}) permanently deleted", 'abtestcraft');

            // Audit log
            ABTestCraft::getInstance()->audit->logTestDeleted($test);
        }

        return $result;
    }

    /**
     * Start a test
     *
     * @return bool|string Returns true on success, or error message string on failure
     */
    public function startTest(Test $test): bool|string
    {
        if (!$test->canStart()) {
            return 'Test cannot be started from its current status.';
        }

        if (!$test->hasEnabledGoals()) {
            return 'At least one conversion goal must be enabled before starting the test.';
        }

        $test->status = Test::STATUS_RUNNING;
        $test->startedAt = new DateTime();

        if (!$this->saveTest($test)) {
            return 'Failed to save test.';
        }

        Craft::info("Split test '{$test->handle}' started", 'abtestcraft');

        // Audit log
        ABTestCraft::getInstance()->audit->logTestStarted($test);

        return true;
    }

    /**
     * Pause a test
     */
    public function pauseTest(Test $test): bool
    {
        if (!$test->canPause()) {
            return false;
        }

        $test->status = Test::STATUS_PAUSED;

        if (!$this->saveTest($test)) {
            return false;
        }

        Craft::info("Split test '{$test->handle}' paused", 'abtestcraft');

        // Audit log
        ABTestCraft::getInstance()->audit->logTestPaused($test);

        return true;
    }

    /**
     * Complete a test with a winner
     */
    public function completeTest(Test $test, ?string $winner = null): bool
    {
        $test->status = Test::STATUS_COMPLETED;
        $test->endedAt = new DateTime();
        $test->winnerVariant = $winner;

        if (!$this->saveTest($test)) {
            return false;
        }

        Craft::info("Split test '{$test->handle}' completed. Winner: " . ($winner ?? 'none'), 'abtestcraft');

        // Audit log
        ABTestCraft::getInstance()->audit->logTestCompleted($test);

        return true;
    }

    /**
     * Create model from record
     */
    private function createModelFromRecord(TestRecord $record): Test
    {
        $test = new Test();
        $test->id = $record->id;
        $test->siteId = $record->siteId;
        $test->name = $record->name;
        $test->handle = $record->handle;
        $test->hypothesis = $record->hypothesis;
        $test->variantDescription = $record->variantDescription;
        $test->learnings = $record->learnings;
        $test->status = $record->status;
        $test->controlEntryId = $record->controlEntryId;
        $test->variantEntryId = $record->variantEntryId;
        $test->trafficSplit = $record->trafficSplit;
        $test->goalType = $record->goalType;
        $test->goalValue = $record->goalValue;
        $test->startedAt = $record->startedAt ? new DateTime($record->startedAt) : null;
        $test->endedAt = $record->endedAt ? new DateTime($record->endedAt) : null;
        $test->winnerVariant = $record->winnerVariant;
        $test->significanceNotifiedAt = $record->significanceNotifiedAt
            ? new DateTime($record->significanceNotifiedAt)
            : null;
        $test->dateCreated = new DateTime($record->dateCreated);
        $test->dateUpdated = new DateTime($record->dateUpdated);
        $test->dateDeleted = $record->dateDeleted ? new DateTime($record->dateDeleted) : null;
        $test->uid = $record->uid;

        return $test;
    }
}
