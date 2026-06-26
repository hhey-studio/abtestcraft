<?php

declare(strict_types=1);

namespace livehand\abtestcraft\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use livehand\abtestcraft\models\Settings;
use livehand\abtestcraft\models\Test;
use livehand\abtestcraft\records\ConversionRecord;
use livehand\abtestcraft\records\DailyStatsRecord;
use livehand\abtestcraft\records\VisitorRecord;
use livehand\abtestcraft\ABTestCraft;
use DateTime;

/**
 * Tracking service - handles impressions and conversions
 */
class TrackingService extends Component
{
    /**
     * Record an impression for a test
     */
    public function recordImpression(Test $test, string $variant): bool
    {
        $today = (new DateTime())->format('Y-m-d');

        // Find or create the aggregate daily stats row. Must scope to
        // goalType = null, otherwise findOne() can match a goal-specific row and
        // increment its impressions (the unique index is testId+date+variant+goalType).
        $record = DailyStatsRecord::findOne([
            'testId' => $test->id,
            'date' => $today,
            'variant' => $variant,
            'goalType' => null,
        ]);

        if (!$record) {
            $record = new DailyStatsRecord();
            $record->testId = $test->id;
            $record->date = $today;
            $record->variant = $variant;
            $record->goalType = null;
            $record->impressions = 0;
            $record->conversions = 0;
        }

        $record->impressions++;

        if (!$record->save()) {
            Craft::error(
                "Failed to save impression record for test '{$test->handle}': " .
                json_encode($record->getErrors()),
                __METHOD__
            );
            return false;
        }

        return true;
    }

    /**
     * Record a conversion for a test
     * Uses database transaction to ensure atomic updates
     */
    public function recordConversion(Test $test, string $conversionType, ?int $goalId = null): bool
    {
        // Get visitor record
        $visitorRecord = ABTestCraft::getInstance()->assignment->getVisitorRecord($test);

        if (!$visitorRecord) {
            Craft::warning("Split Test: Cannot record conversion - visitor not found for test '{$test->handle}'", __METHOD__);
            return false;
        }

        $providedGoalId = $goalId;
        $goal = ABTestCraft::getInstance()->goals->getGoalByTestAndType((int) $test->id, $conversionType);

        if ($providedGoalId !== null) {
            $providedGoal = ABTestCraft::getInstance()->goals->getGoalById($providedGoalId);

            if (
                $providedGoal &&
                $providedGoal->testId === $test->id &&
                $providedGoal->goalType === $conversionType
            ) {
                $goal = $providedGoal;
            }
        }

        $isLegacyGoal = !$goal &&
            $providedGoalId === null &&
            !$test->hasGoals() &&
            $conversionType === $test->goalType;

        if (
            !$isLegacyGoal &&
            (
                !$goal ||
                $goal->testId !== $test->id ||
                $goal->goalType !== $conversionType ||
                !$goal->isEnabled
            )
        ) {
            Craft::warning(
                "Split Test: Ignoring conversion for unconfigured goal '{$conversionType}' on test '{$test->handle}'",
                __METHOD__
            );
            return false;
        }

        $goalId = $goal?->id;

        // Check for duplicate conversions based on counting mode setting
        $mode = ABTestCraft::getInstance()->getSettings()->conversionCountingMode ?? Settings::COUNTING_PER_GOAL_TYPE;

        $existingConversion = false;

        if ($mode === Settings::COUNTING_FIRST_ONLY) {
            // First conversion only: check if visitor has ANY conversion
            $existingConversion = (new Query())
                ->from('{{%abtestcraft_conversions}}')
                ->where([
                    'testId' => $test->id,
                    'visitorId' => $visitorRecord->visitorId,
                ])
                ->exists();
        } elseif ($mode === Settings::COUNTING_PER_GOAL_TYPE) {
            // Per goal type (default): check if visitor has conversion for THIS goal type
            $existingConversion = (new Query())
                ->from('{{%abtestcraft_conversions}}')
                ->where([
                    'testId' => $test->id,
                    'visitorId' => $visitorRecord->visitorId,
                    'conversionType' => $conversionType,
                ])
                ->exists();
        }
        // Unlimited mode: existingConversion stays false, always allow

        if ($existingConversion) {
            return true; // Already converted based on counting mode rules
        }

        // Use transaction to ensure atomic updates
        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            $dateConverted = (new DateTime())->format('Y-m-d H:i:s');

            $conversionRecord = new ConversionRecord();
            $conversionRecord->testId = $test->id;
            $conversionRecord->visitorId = $visitorRecord->visitorId;
            $conversionRecord->variant = $visitorRecord->variant;
            $conversionRecord->conversionType = $conversionType;
            $conversionRecord->goalId = $goalId;
            $conversionRecord->dateConverted = $dateConverted;

            if (!$conversionRecord->save()) {
                throw new \Exception(
                    "Failed to save conversion event record: " .
                    json_encode($conversionRecord->getErrors())
                );
            }

            // Update visitor record with this conversion
            $visitorRecord->converted = true;
            $visitorRecord->conversionType = $conversionType;
            $visitorRecord->goalId = $goalId;
            $visitorRecord->dateConverted = $dateConverted;

            if (!$visitorRecord->save()) {
                throw new \Exception(
                    "Failed to save visitor conversion record: " .
                    json_encode($visitorRecord->getErrors())
                );
            }

            // Update daily stats (with goal type tracking)
            $today = (new DateTime())->format('Y-m-d');

            // Update general stats (without goal type for backward compatibility)
            $statsRecord = DailyStatsRecord::findOne([
                'testId' => $test->id,
                'date' => $today,
                'variant' => $visitorRecord->variant,
                'goalType' => null,
            ]);

            if (!$statsRecord) {
                $statsRecord = new DailyStatsRecord();
                $statsRecord->testId = $test->id;
                $statsRecord->date = $today;
                $statsRecord->variant = $visitorRecord->variant;
                $statsRecord->impressions = 0;
                $statsRecord->conversions = 0;
                $statsRecord->goalType = null;
            }

            $statsRecord->conversions++;
            if (!$statsRecord->save()) {
                throw new \Exception(
                    "Failed to save stats record: " .
                    json_encode($statsRecord->getErrors())
                );
            }

            // Also update goal-specific stats
            $goalStatsRecord = DailyStatsRecord::findOne([
                'testId' => $test->id,
                'date' => $today,
                'variant' => $visitorRecord->variant,
                'goalType' => $conversionType,
            ]);

            if (!$goalStatsRecord) {
                $goalStatsRecord = new DailyStatsRecord();
                $goalStatsRecord->testId = $test->id;
                $goalStatsRecord->date = $today;
                $goalStatsRecord->variant = $visitorRecord->variant;
                $goalStatsRecord->impressions = 0;
                $goalStatsRecord->conversions = 0;
                $goalStatsRecord->goalType = $conversionType;
            }

            $goalStatsRecord->conversions++;
            if (!$goalStatsRecord->save()) {
                throw new \Exception(
                    "Failed to save goal stats record: " .
                    json_encode($goalStatsRecord->getErrors())
                );
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            Craft::error(
                "Failed to record conversion for test '{$test->handle}': " . $e->getMessage(),
                __METHOD__
            );
            return false;
        }

        // Check if test has reached significance (outside transaction)
        $this->checkSignificance($test);

        return true;
    }

    /**
     * Record conversion by test handle (used by tracking endpoint)
     */
    public function recordConversionByHandle(string $testHandle, string $conversionType, ?int $goalId = null): bool
    {
        $test = ABTestCraft::getInstance()->tests->getTestByHandle($testHandle);

        if (!$test || !$test->isRunning()) {
            return false;
        }

        return $this->recordConversion($test, $conversionType, $goalId);
    }

    /**
     * Get total impressions for a test
     */
    public function getTotalImpressions(Test $test): array
    {
        $stats = (new Query())
            ->select(['variant', 'SUM(impressions) as total'])
            ->from('{{%abtestcraft_daily_stats}}')
            ->where(['testId' => $test->id])
            ->andWhere(['goalType' => null])
            ->groupBy(['variant'])
            ->all();

        $result = ['control' => 0, 'variant' => 0];

        foreach ($stats as $stat) {
            $result[$stat['variant']] = (int) $stat['total'];
        }

        return $result;
    }

    /**
     * Get total conversions for a test
     */
    public function getTotalConversions(Test $test): array
    {
        $stats = (new Query())
            ->select(['variant', 'SUM(conversions) as total'])
            ->from('{{%abtestcraft_daily_stats}}')
            ->where(['testId' => $test->id])
            ->andWhere(['goalType' => null])
            ->groupBy(['variant'])
            ->all();

        $result = ['control' => 0, 'variant' => 0];

        foreach ($stats as $stat) {
            $result[$stat['variant']] = (int) $stat['total'];
        }

        return $result;
    }

    /**
     * Get unique visitors for a test
     */
    public function getUniqueVisitors(Test $test): array
    {
        $stats = (new Query())
            ->select(['variant', 'COUNT(*) as total'])
            ->from('{{%abtestcraft_visitors}}')
            ->where(['testId' => $test->id])
            ->groupBy(['variant'])
            ->all();

        $result = ['control' => 0, 'variant' => 0];

        foreach ($stats as $stat) {
            $result[$stat['variant']] = (int) $stat['total'];
        }

        return $result;
    }

    /**
     * Get the number of unique visitors who converted, per variant.
     *
     * Counts visitor rows (one per visitor/test), so a visitor is counted once
     * regardless of how many sessions or goal types they converted on. This is
     * the correct numerator for visitor-level conversion rate and significance.
     */
    public function getConvertedVisitors(Test $test): array
    {
        $stats = (new Query())
            ->select([
                'variant',
                new \yii\db\Expression('COUNT(DISTINCT [[visitorId]]) AS [[total]]'),
            ])
            ->from('{{%abtestcraft_conversions}}')
            ->where(['testId' => $test->id])
            ->groupBy(['variant'])
            ->all();

        $result = ['control' => 0, 'variant' => 0];

        foreach ($stats as $stat) {
            $result[$stat['variant']] = (int) $stat['total'];
        }

        return $result;
    }

    /**
     * Check if test has reached statistical significance and notify if enabled
     * Delegates to NotificationService for actual notification handling
     */
    private function checkSignificance(Test $test): void
    {
        ABTestCraft::getInstance()->notifications->checkAndNotifySignificance($test);
    }
}
