<?php

declare(strict_types=1);

namespace livehand\abtestcraft\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use livehand\abtestcraft\models\Goal;
use livehand\abtestcraft\models\Test;
use livehand\abtestcraft\records\GoalRecord;
use DateTime;

/**
 * Goals service - CRUD operations for conversion goals
 */
class GoalsService extends Component
{
    /**
     * Get all goals for a test
     */
    public function getGoalsByTestId(int $testId): array
    {
        $records = GoalRecord::find()
            ->where(['testId' => $testId])
            ->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        return array_map(fn($record) => $this->createModelFromRecord($record), $records);
    }

    /**
     * Get enabled goals for a test
     */
    public function getEnabledGoalsByTestId(int $testId): array
    {
        $records = GoalRecord::find()
            ->where(['testId' => $testId, 'isEnabled' => true])
            ->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        return array_map(fn($record) => $this->createModelFromRecord($record), $records);
    }

    /**
     * Get a goal by ID
     */
    public function getGoalById(int $id): ?Goal
    {
        $record = GoalRecord::findOne($id);

        if (!$record) {
            return null;
        }

        return $this->createModelFromRecord($record);
    }

    /**
     * Get a goal by test ID and type
     */
    public function getGoalByTestAndType(int $testId, string $goalType): ?Goal
    {
        $record = GoalRecord::findOne([
            'testId' => $testId,
            'goalType' => $goalType,
        ]);

        if (!$record) {
            return null;
        }

        return $this->createModelFromRecord($record);
    }

    /**
     * Save a goal
     */
    public function saveGoal(Goal $goal): bool
    {
        if (!$goal->validate()) {
            return false;
        }

        if ($goal->id) {
            $record = GoalRecord::findOne($goal->id);
            if (!$record) {
                return false;
            }
        } else {
            $record = new GoalRecord();
        }

        $record->testId = $goal->testId;
        $record->goalType = $goal->goalType;
        $record->isEnabled = $goal->isEnabled;
        $record->config = $goal->config;
        $record->sortOrder = $goal->sortOrder;

        if (!$record->save()) {
            $goal->addErrors($record->getErrors());
            return false;
        }

        $goal->id = $record->id;
        $goal->dateCreated = new DateTime($record->dateCreated);
        $goal->dateUpdated = new DateTime($record->dateUpdated);
        $goal->uid = $record->uid;

        return true;
    }

    /**
     * Save multiple goals for a test (replaces all existing goals)
     */
    public function saveGoalsForTest(Test $test, array $goalsData): bool
    {
        if (!$test->id) {
            return false;
        }

        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            // Delete existing goals
            GoalRecord::deleteAll(['testId' => $test->id]);

            // Save new goals
            $sortOrder = 0;
            foreach ($goalsData as $goalData) {
                if (!($goalData['isEnabled'] ?? false)) {
                    continue;
                }

                $goal = new Goal();
                $goal->testId = $test->id;
                $goal->goalType = $goalData['goalType'];
                $goal->isEnabled = true;
                $goal->config = $goalData['config'] ?? null;
                $goal->sortOrder = $sortOrder++;

                if (!$this->saveGoal($goal)) {
                    $transaction->rollBack();
                    return false;
                }
            }

            $transaction->commit();
            ABTestCraft::getInstance()->stats->invalidateStatsCache((int) $test->id);
            return true;
        } catch (\Exception $e) {
            $transaction->rollBack();
            Craft::error('Failed to save goals for test: ' . $e->getMessage(), 'abtestcraft');
            return false;
        }
    }

    /**
     * Delete a goal
     */
    public function deleteGoal(Goal $goal): bool
    {
        if (!$goal->id) {
            return false;
        }

        $record = GoalRecord::findOne($goal->id);

        if (!$record) {
            return false;
        }

        $testId = (int) $goal->testId;
        $result = (bool) $record->delete();
        if ($result) {
            ABTestCraft::getInstance()->stats->invalidateStatsCache($testId);
        }

        return $result;
    }

    /**
     * Delete all goals for a test
     */
    public function deleteGoalsByTestId(int $testId): bool
    {
        $result = (bool) GoalRecord::deleteAll(['testId' => $testId]);
        ABTestCraft::getInstance()->stats->invalidateStatsCache($testId);

        return $result;
    }

    /**
     * Get goals config for JavaScript tracking
     */
    public function getGoalsJsConfig(int $testId): array
    {
        $goals = $this->getEnabledGoalsByTestId($testId);

        $config = [];
        foreach ($goals as $goal) {
            $config[$goal->goalType] = $goal->toJsConfig();
        }

        return $config;
    }

    /**
     * Create default goals from legacy test data
     * Used for backward compatibility when migrating from single-goal to multi-goal
     */
    public function createDefaultGoalsFromLegacy(Test $test): bool
    {
        if (!$test->id || !$test->goalType) {
            return false;
        }

        // Check if goals already exist
        $existingGoals = $this->getGoalsByTestId($test->id);
        if (!empty($existingGoals)) {
            return true; // Already migrated
        }

        $goal = new Goal();
        $goal->testId = $test->id;
        $goal->goalType = $test->goalType;
        $goal->isEnabled = true;

        // Build config based on legacy goalType and goalValue
        switch ($test->goalType) {
            case Goal::TYPE_PAGE:
                $goal->setPageConfig($test->goalValue ?? '', Goal::MATCH_EXACT);
                break;
            case Goal::TYPE_DOWNLOAD:
                $extensions = $test->goalValue
                    ? array_map('trim', explode(',', $test->goalValue))
                    : ['pdf', 'doc', 'docx', 'zip'];
                $goal->setDownloadConfig($extensions);
                break;
            case Goal::TYPE_FORM:
                $goal->setFormConfig(
                    '.freeform-form, form',
                    Goal::SUCCESS_ANY,
                    null
                );
                break;
            // Phone and Email don't need config
        }

        return $this->saveGoal($goal);
    }

    /**
     * Create model from record
     */
    private function createModelFromRecord(GoalRecord $record): Goal
    {
        $goal = new Goal();
        $goal->id = $record->id;
        $goal->testId = $record->testId;
        $goal->goalType = $record->goalType;
        $goal->isEnabled = (bool) $record->isEnabled;
        $goal->config = is_string($record->config) ? Json::decode($record->config) : $record->config;
        $goal->sortOrder = $record->sortOrder;
        $goal->dateCreated = new DateTime($record->dateCreated);
        $goal->dateUpdated = new DateTime($record->dateUpdated);
        $goal->uid = $record->uid;

        return $goal;
    }
}
