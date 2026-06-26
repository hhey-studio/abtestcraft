<?php

declare(strict_types=1);

namespace livehand\abtestcraft\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use livehand\abtestcraft\models\Test;
use livehand\abtestcraft\ABTestCraft;

/**
 * Stats service - calculates test results and statistical significance
 */
class StatsService extends Component
{
    /**
     * Get summary stats for multiple tests in a single batch query
     * Used for the tests index page to avoid N+1 queries
     */
    public function getBatchTestStats(array $tests): array
    {
        if (empty($tests)) {
            return [];
        }

        $testIds = array_map(fn(Test $test) => $test->id, $tests);

        // Batch query for impressions and conversions from daily_stats
        $dailyStats = (new Query())
            ->select(['testId', 'variant', 'SUM(impressions) as impressions', 'SUM(conversions) as conversions'])
            ->from('{{%abtestcraft_daily_stats}}')
            ->where(['testId' => $testIds])
            ->andWhere(['goalType' => null])
            ->groupBy(['testId', 'variant'])
            ->all();

        // Batch query for unique visitors
        $visitorStats = (new Query())
            ->select(['testId', 'variant', 'COUNT(*) as total'])
            ->from('{{%abtestcraft_visitors}}')
            ->where(['testId' => $testIds])
            ->groupBy(['testId', 'variant'])
            ->all();

        // Batch query for unique visitors who converted
        $convertedVisitorStats = (new Query())
            ->select([
                'testId',
                'variant',
                new \yii\db\Expression('COUNT(DISTINCT [[visitorId]]) AS [[total]]'),
            ])
            ->from('{{%abtestcraft_conversions}}')
            ->where(['testId' => $testIds])
            ->groupBy(['testId', 'variant'])
            ->all();

        // Build lookup maps
        $statsMap = [];
        foreach ($dailyStats as $stat) {
            $testId = $stat['testId'];
            $variant = $stat['variant'];
            if (!isset($statsMap[$testId])) {
                $statsMap[$testId] = [
                    'impressions' => ['control' => 0, 'variant' => 0],
                    'conversions' => ['control' => 0, 'variant' => 0],
                    'visitors' => ['control' => 0, 'variant' => 0],
                    'convertedVisitors' => ['control' => 0, 'variant' => 0],
                ];
            }
            $statsMap[$testId]['impressions'][$variant] = (int) $stat['impressions'];
            $statsMap[$testId]['conversions'][$variant] = (int) $stat['conversions'];
        }

        foreach ($visitorStats as $stat) {
            $testId = $stat['testId'];
            $variant = $stat['variant'];
            if (!isset($statsMap[$testId])) {
                $statsMap[$testId] = [
                    'impressions' => ['control' => 0, 'variant' => 0],
                    'conversions' => ['control' => 0, 'variant' => 0],
                    'visitors' => ['control' => 0, 'variant' => 0],
                    'convertedVisitors' => ['control' => 0, 'variant' => 0],
                ];
            }
            $statsMap[$testId]['visitors'][$variant] = (int) $stat['total'];
        }

        foreach ($convertedVisitorStats as $stat) {
            $testId = $stat['testId'];
            $variant = $stat['variant'];
            if (!isset($statsMap[$testId])) {
                $statsMap[$testId] = [
                    'impressions' => ['control' => 0, 'variant' => 0],
                    'conversions' => ['control' => 0, 'variant' => 0],
                    'visitors' => ['control' => 0, 'variant' => 0],
                    'convertedVisitors' => ['control' => 0, 'variant' => 0],
                ];
            }
            $statsMap[$testId]['convertedVisitors'][$variant] = (int) $stat['total'];
        }

        // Calculate stats for each test
        $result = [];
        foreach ($tests as $test) {
            $testStats = $statsMap[$test->id] ?? [
                'impressions' => ['control' => 0, 'variant' => 0],
                'conversions' => ['control' => 0, 'variant' => 0],
                'visitors' => ['control' => 0, 'variant' => 0],
                'convertedVisitors' => ['control' => 0, 'variant' => 0],
            ];

            $conversionRates = [
                'control' => $this->calculateConversionRate(
                    $testStats['visitors']['control'],
                    $testStats['convertedVisitors']['control']
                ),
                'variant' => $this->calculateConversionRate(
                    $testStats['visitors']['variant'],
                    $testStats['convertedVisitors']['variant']
                ),
            ];

            $significance = $this->calculateSignificance(
                $testStats['visitors']['control'],
                $testStats['convertedVisitors']['control'],
                $testStats['visitors']['variant'],
                $testStats['convertedVisitors']['variant']
            );

            $settings = ABTestCraft::getInstance()->getSettings();
            $isSignificant = $significance['confidence'] >= $settings->significanceThreshold;
            $improvement = $this->calculateImprovement($conversionRates['control'], $conversionRates['variant']);
            $totalVisitors = $testStats['visitors']['control'] + $testStats['visitors']['variant'];
            $sampleSizeNeeded = $this->calculateSampleSizeNeeded($conversionRates['control']);
            $progressPercent = $sampleSizeNeeded > 0
                ? min(100, round(($totalVisitors / ($sampleSizeNeeded * 2)) * 100))
                : 0;

            $result[$test->id] = [
                'impressions' => $testStats['impressions'],
                'conversions' => $testStats['conversions'],
                'visitors' => $testStats['visitors'],
                'convertedVisitors' => $testStats['convertedVisitors'],
                'conversionRates' => $conversionRates,
                'confidence' => $significance['confidence'],
                'isSignificant' => $isSignificant,
                'improvement' => $improvement,
                'winner' => $this->determineWinner($conversionRates, $isSignificant),
                'totalVisitors' => $totalVisitors,
                'progressPercent' => $progressPercent,
            ];
        }

        return $result;
    }

    /**
     * Get comprehensive stats for a test
     * Results are cached indefinitely for completed tests
     */
    public function getTestStats(Test $test): array
    {
        // For completed tests, check cache first (stats won't change)
        if ($test->isCompleted() && $test->id !== null) {
            $cacheKey = "abtestcraft_stats_{$test->id}";
            $cached = Craft::$app->getCache()->get($cacheKey);
            if ($cached !== false) {
                return $cached;
            }
        }

        $impressions = ABTestCraft::getInstance()->tracking->getTotalImpressions($test);
        $conversions = ABTestCraft::getInstance()->tracking->getTotalConversions($test);
        $visitors = ABTestCraft::getInstance()->tracking->getUniqueVisitors($test);
        $convertedVisitors = ABTestCraft::getInstance()->tracking->getConvertedVisitors($test);

        // Conversion rate and significance are computed per UNIQUE VISITOR, not
        // per session/impression. Using sessions would violate the chi-squared
        // independence assumption (a visitor with N sessions counted N times),
        // overstating confidence and risking premature winners. impressions and
        // conversions (events) are retained below as engagement metrics only.
        $conversionRates = [
            'control' => $this->calculateConversionRate($visitors['control'], $convertedVisitors['control']),
            'variant' => $this->calculateConversionRate($visitors['variant'], $convertedVisitors['variant']),
        ];

        $significance = $this->calculateSignificance(
            $visitors['control'],
            $convertedVisitors['control'],
            $visitors['variant'],
            $convertedVisitors['variant']
        );

        $settings = ABTestCraft::getInstance()->getSettings();
        $isSignificant = $significance['confidence'] >= $settings->significanceThreshold;

        $improvement = $this->calculateImprovement($conversionRates['control'], $conversionRates['variant']);

        // Calculate sessions per visitor (engagement metric)
        $sessionsPerVisitor = [
            'control' => $visitors['control'] > 0
                ? round($impressions['control'] / $visitors['control'], 1)
                : 0,
            'variant' => $visitors['variant'] > 0
                ? round($impressions['variant'] / $visitors['variant'], 1)
                : 0,
        ];

        // Calculate 95% confidence intervals for conversion rates
        $confidenceIntervals = [
            'control' => $this->calculateConfidenceInterval(
                $conversionRates['control'] / 100,
                $visitors['control']
            ),
            'variant' => $this->calculateConfidenceInterval(
                $conversionRates['variant'] / 100,
                $visitors['variant']
            ),
        ];

        // Calculate progress toward statistical significance
        $totalVisitors = $visitors['control'] + $visitors['variant'];
        $sampleSizeNeeded = $this->calculateSampleSizeNeeded($conversionRates['control']);
        $progressPercent = $sampleSizeNeeded > 0
            ? min(100, round(($totalVisitors / ($sampleSizeNeeded * 2)) * 100))
            : 0;

        $result = [
            'impressions' => $impressions,
            'conversions' => $conversions,
            'visitors' => $visitors,
            'convertedVisitors' => $convertedVisitors,
            'conversionRates' => $conversionRates,
            'confidence' => $significance['confidence'],
            'chiSquared' => $significance['chiSquared'],
            'isSignificant' => $isSignificant,
            'improvement' => $improvement,
            'winner' => $this->determineWinner($conversionRates, $isSignificant),
            'dailyStats' => $this->getDailyStats($test),
            'goalStats' => $this->getGoalStats($test),
            'sampleSizeNeeded' => $sampleSizeNeeded,
            // New metrics
            'sessionsPerVisitor' => $sessionsPerVisitor,
            'confidenceIntervals' => $confidenceIntervals,
            'totalVisitors' => $totalVisitors,
            'progressPercent' => $progressPercent,
        ];

        // Cache stats for completed tests indefinitely
        if ($test->isCompleted() && $test->id !== null) {
            Craft::$app->getCache()->set("abtestcraft_stats_{$test->id}", $result, 0);
        }

        return $result;
    }

    /**
     * Invalidate cached stats for a test
     * Should be called when test data is modified
     */
    public function invalidateStatsCache(int $testId): void
    {
        Craft::$app->getCache()->delete("abtestcraft_stats_{$testId}");
    }

    /**
     * Get per-goal conversion stats
     */
    private function getGoalStats(Test $test): array
    {
        $stats = (new Query())
            ->select(['goalType', 'variant', 'SUM(conversions) as total'])
            ->from('{{%abtestcraft_daily_stats}}')
            ->where(['testId' => $test->id])
            ->andWhere(['not', ['goalType' => null]])
            ->groupBy(['goalType', 'variant'])
            ->all();

        $result = [];

        foreach ($stats as $stat) {
            $goalType = $stat['goalType'];

            if (!isset($result[$goalType])) {
                $result[$goalType] = [
                    'control' => 0,
                    'variant' => 0,
                ];
            }

            $result[$goalType][$stat['variant']] = (int) $stat['total'];
        }

        return $result;
    }

    /**
     * Calculate conversion rate
     */
    private function calculateConversionRate(int $impressions, int $conversions): float
    {
        if ($impressions === 0) {
            return 0.0;
        }
        return round(($conversions / $impressions) * 100, 2);
    }

    /**
     * Calculate statistical significance using Pearson's Chi-squared test
     *
     * This implements a 2x2 contingency table Chi-squared test to determine
     * if there's a statistically significant difference between control
     * and variant conversion rates.
     *
     * The contingency table structure:
     *                  | Converted | Not Converted |
     * Control          |    a      |      b        |
     * Variant          |    c      |      d        |
     *
     * Chi-squared formula: Σ (observed - expected)² / expected
     *
     * The test assumes:
     * - Independent observations (each visitor counted once)
     * - Expected frequencies >= 5 in each cell (we require >= 10 impressions)
     * - Random sampling (visitors randomly assigned to variants)
     *
     * Degrees of freedom: (rows-1) * (cols-1) = (2-1) * (2-1) = 1
     *
     * @link https://en.wikipedia.org/wiki/Chi-squared_test
     * @link https://www.statisticshowto.com/probability-and-statistics/chi-square/
     *
     * @param int $controlImpressions Total visitors who saw control
     * @param int $controlConversions Visitors who converted on control
     * @param int $variantImpressions Total visitors who saw variant
     * @param int $variantConversions Visitors who converted on variant
     * @return array{confidence: float, chiSquared: float}
     */
    private function calculateSignificance(
        int $controlImpressions,
        int $controlConversions,
        int $variantImpressions,
        int $variantConversions
    ): array {
        // Need minimum sample size for meaningful results
        if ($controlImpressions < 10 || $variantImpressions < 10) {
            return ['confidence' => 0.0, 'chiSquared' => 0.0];
        }

        $controlNonConversions = $controlImpressions - $controlConversions;
        $variantNonConversions = $variantImpressions - $variantConversions;

        $totalConversions = $controlConversions + $variantConversions;
        $totalNonConversions = $controlNonConversions + $variantNonConversions;
        $totalControl = $controlImpressions;
        $totalVariant = $variantImpressions;
        $grandTotal = $totalControl + $totalVariant;

        // Calculate expected values
        $expectedControlConversions = ($totalControl * $totalConversions) / $grandTotal;
        $expectedControlNonConversions = ($totalControl * $totalNonConversions) / $grandTotal;
        $expectedVariantConversions = ($totalVariant * $totalConversions) / $grandTotal;
        $expectedVariantNonConversions = ($totalVariant * $totalNonConversions) / $grandTotal;

        // Avoid division by zero
        if (
            $expectedControlConversions == 0 || $expectedControlNonConversions == 0 ||
            $expectedVariantConversions == 0 || $expectedVariantNonConversions == 0
        ) {
            return ['confidence' => 0.0, 'chiSquared' => 0.0];
        }

        // Calculate Chi-squared statistic
        $chiSquared = 0;
        $chiSquared += pow($controlConversions - $expectedControlConversions, 2) / $expectedControlConversions;
        $chiSquared += pow($controlNonConversions - $expectedControlNonConversions, 2) / $expectedControlNonConversions;
        $chiSquared += pow($variantConversions - $expectedVariantConversions, 2) / $expectedVariantConversions;
        $chiSquared += pow($variantNonConversions - $expectedVariantNonConversions, 2) / $expectedVariantNonConversions;

        // Convert Chi-squared to confidence level (1 degree of freedom)
        $confidence = $this->chiSquaredToConfidence($chiSquared);

        return [
            'confidence' => round($confidence, 4),
            'chiSquared' => round($chiSquared, 4),
        ];
    }

    /**
     * Convert Chi-squared statistic to confidence level using critical values table
     *
     * Uses the inverse of the Chi-squared cumulative distribution function (CDF)
     * for 1 degree of freedom. The critical values table provides known points
     * on the inverse CDF, and we interpolate between them for smoother results.
     *
     * Critical values for Chi-squared distribution with df=1:
     * - 0.455 → 50% confidence (p < 0.50)
     * - 2.706 → 90% confidence (p < 0.10)
     * - 3.841 → 95% confidence (p < 0.05) - Common threshold
     * - 6.635 → 99% confidence (p < 0.01)
     *
     * Interpretation:
     * - 95% confidence means there's only a 5% probability the observed
     *   difference occurred by chance (if null hypothesis were true)
     *
     * @link https://en.wikipedia.org/wiki/Chi-squared_distribution
     * @link https://www.itl.nist.gov/div898/handbook/eda/section3/eda3674.htm
     *
     * @param float $chiSquared The calculated Chi-squared statistic
     * @return float Confidence level between 0.0 and 0.999
     */
    private function chiSquaredToConfidence(float $chiSquared): float
    {
        // Chi-squared critical values for 1 degree of freedom
        // Using arrays of [confidence, criticalValue] pairs to avoid float key deprecation in PHP 8.1+
        $criticalValues = [
            [0.50, 0.455],   // p = 0.50
            [0.75, 1.323],   // p = 0.25
            [0.80, 1.642],   // p = 0.20
            [0.85, 2.072],   // p = 0.15
            [0.90, 2.706],   // p = 0.10
            [0.95, 3.841],   // p = 0.05 (common significance threshold)
            [0.975, 5.024],  // p = 0.025
            [0.99, 6.635],   // p = 0.01 (highly significant)
            [0.995, 7.879],  // p = 0.005
            [0.999, 10.828], // p = 0.001 (extremely significant)
        ];

        $lastConfidence = 0.0;
        $lastCritical = 0.0;

        foreach ($criticalValues as [$confidence, $criticalValue]) {
            if ($chiSquared < $criticalValue) {
                // Interpolate between last confidence and this one
                if ($lastConfidence === 0.0) {
                    return $confidence * ($chiSquared / $criticalValue);
                }

                $fraction = ($chiSquared - $lastCritical) / ($criticalValue - $lastCritical);
                return $lastConfidence + ($confidence - $lastConfidence) * $fraction;
            }
            $lastConfidence = $confidence;
            $lastCritical = $criticalValue;
        }

        return 0.999; // Very high significance
    }

    /**
     * Calculate 95% confidence interval for a conversion rate
     */
    private function calculateConfidenceInterval(float $rate, int $n): array
    {
        if ($n === 0) {
            return ['lower' => 0, 'upper' => 0, 'margin' => 0];
        }

        $z = 1.96; // 95% confidence
        $margin = $z * sqrt($rate * (1 - $rate) / $n);

        return [
            'lower' => round(max(0, ($rate - $margin)) * 100, 1),
            'upper' => round(min(1, ($rate + $margin)) * 100, 1),
            'margin' => round($margin * 100, 1),
        ];
    }

    /**
     * Calculate improvement percentage
     *
     * Returns null when control rate is 0 since percentage improvement
     * cannot be calculated from a zero baseline.
     */
    private function calculateImprovement(float $controlRate, float $variantRate): ?float
    {
        if ($controlRate === 0.0) {
            return null;
        }
        return round((($variantRate - $controlRate) / $controlRate) * 100, 2);
    }

    /**
     * Determine the winner
     */
    private function determineWinner(array $conversionRates, bool $isSignificant): ?string
    {
        if (!$isSignificant) {
            return null;
        }

        if ($conversionRates[Test::VARIANT_VARIANT] > $conversionRates[Test::VARIANT_CONTROL]) {
            return Test::VARIANT_VARIANT;
        } elseif ($conversionRates[Test::VARIANT_CONTROL] > $conversionRates[Test::VARIANT_VARIANT]) {
            return Test::VARIANT_CONTROL;
        }

        return null;
    }

    /**
     * Get daily stats for charting
     */
    private function getDailyStats(Test $test): array
    {
        $stats = (new Query())
            ->select(['date', 'variant', 'impressions', 'conversions'])
            ->from('{{%abtestcraft_daily_stats}}')
            ->where(['testId' => $test->id])
            ->andWhere(['goalType' => null]) // Only overall stats, not per-goal
            ->orderBy(['date' => SORT_ASC])
            ->all();

        $result = [];

        foreach ($stats as $stat) {
            $date = $stat['date'];

            if (!isset($result[$date])) {
                $result[$date] = [
                    'date' => $date,
                    'control' => ['impressions' => 0, 'conversions' => 0, 'rate' => 0],
                    'variant' => ['impressions' => 0, 'conversions' => 0, 'rate' => 0],
                ];
            }

            $impressions = (int) $stat['impressions'];
            $conversions = (int) $stat['conversions'];

            $result[$date][$stat['variant']] = [
                'impressions' => $impressions,
                'conversions' => $conversions,
                'rate' => $impressions > 0 ? round(($conversions / $impressions) * 100, 2) : 0,
            ];
        }

        return array_values($result);
    }

    /**
     * Calculate minimum sample size needed for statistical significance
     * Based on baseline conversion rate and configured minimum detectable effect
     */
    private function calculateSampleSizeNeeded(float $baselineRate): int
    {
        if ($baselineRate <= 0) {
            return 1000; // Default if no baseline
        }

        // Convert percentage to proportion
        $p = $baselineRate / 100;

        // Get MDE from settings (defaults to 10%)
        $settings = ABTestCraft::getInstance()->getSettings();
        $mde = $settings->minimumDetectableEffect;

        // Standard values for 95% confidence and 80% power
        $zAlpha = 1.96; // 95% confidence (two-tailed)
        $zBeta = 0.84;  // 80% power

        // Sample size formula per variation
        $delta = $p * $mde;
        $pooledVariance = 2 * $p * (1 - $p);
        $n = pow($zAlpha + $zBeta, 2) * $pooledVariance / pow($delta, 2);

        // Return per variation, rounded up
        return (int) ceil($n);
    }

    /**
     * Get time estimate to reach significance
     */
    public function getTimeEstimate(Test $test): ?array
    {
        $stats = $this->getTestStats($test);

        if ($stats['isSignificant']) {
            return ['reached' => true, 'daysRemaining' => 0];
        }

        $totalImpressions = $stats['impressions']['control'] + $stats['impressions']['variant'];

        if ($totalImpressions < 10) {
            return null; // Not enough data for estimate
        }

        // Calculate daily rate
        $daysRunning = 1;
        if ($test->startedAt) {
            $daysRunning = max(1, (new \DateTime())->diff($test->startedAt)->days);
        }

        $dailyRate = $totalImpressions / $daysRunning;

        if ($dailyRate < 1) {
            return null; // Traffic too low for estimate
        }

        // Needed per variation
        $sampleNeeded = $stats['sampleSizeNeeded'] * 2;
        $remaining = max(0, $sampleNeeded - $totalImpressions);
        $daysRemaining = (int) ceil($remaining / $dailyRate);

        return [
            'reached' => false,
            'daysRemaining' => $daysRemaining,
            'impressionsNeeded' => $sampleNeeded,
            'currentImpressions' => $totalImpressions,
            'dailyRate' => round($dailyRate),
        ];
    }
}
