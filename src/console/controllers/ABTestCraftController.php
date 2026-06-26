<?php

declare(strict_types=1);

namespace livehand\abtestcraft\console\controllers;

use Craft;
use craft\console\Controller;
use livehand\abtestcraft\models\Test;
use livehand\abtestcraft\ABTestCraft;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Console commands for ABTestCraft plugin
 */
class ABTestCraftController extends Controller
{
    /**
     * @var bool Whether to force the operation without confirmation
     */
    public bool $force = false;

    /**
     * @var int Number of days to keep transient rate-limit data
     */
    public int $days = 90;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        switch ($actionID) {
            case 'cleanup':
                $options[] = 'days';
                $options[] = 'force';
                break;
            case 'recalculate-stats':
                $options[] = 'force';
                break;
        }

        return $options;
    }

    /**
     * List all A/B tests with their status
     *
     * Usage: ./craft abtestcraft/list
     */
    public function actionList(): int
    {
        $tests = ABTestCraft::getInstance()->tests->getAllTests();

        if (empty($tests)) {
            $this->stdout("No A/B tests found.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $this->stdout("\n");
        $this->stdout(str_pad('ID', 6) . str_pad('Status', 12) . str_pad('Handle', 30) . "Name\n", Console::BOLD);
        $this->stdout(str_repeat('-', 80) . "\n");

        foreach ($tests as $test) {
            $statusColor = match ($test->status) {
                Test::STATUS_RUNNING => Console::FG_GREEN,
                Test::STATUS_PAUSED => Console::FG_YELLOW,
                Test::STATUS_COMPLETED => Console::FG_CYAN,
                default => Console::FG_GREY,
            };

            $this->stdout(str_pad((string)$test->id, 6));
            $this->stdout(str_pad($test->status, 12), $statusColor);
            $this->stdout(str_pad($test->handle, 30));
            $this->stdout($test->name . "\n");
        }

        $this->stdout("\n");
        $this->stdout("Total: " . count($tests) . " tests\n", Console::BOLD);

        return ExitCode::OK;
    }

    /**
     * Show detailed stats for a specific test
     *
     * Usage: ./craft abtestcraft/stats <testId>
     *
     * @param int $testId The test ID to show stats for
     */
    public function actionStats(int $testId): int
    {
        $test = ABTestCraft::getInstance()->tests->getTestById($testId);

        if (!$test) {
            $this->stderr("Test not found: {$testId}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $stats = ABTestCraft::getInstance()->stats->getTestStats($test);

        $this->stdout("\n");
        $this->stdout("ABTestCraft: {$test->name}\n", Console::BOLD);
        $this->stdout("Handle: {$test->handle}\n");
        $this->stdout("Status: {$test->status}\n");
        $this->stdout(str_repeat('-', 50) . "\n\n");

        $this->stdout("Impressions:\n", Console::BOLD);
        $this->stdout("  Control: {$stats['impressions']['control']}\n");
        $this->stdout("  Variant: {$stats['impressions']['variant']}\n\n");

        $this->stdout("Conversions:\n", Console::BOLD);
        $this->stdout("  Control: {$stats['conversions']['control']}\n");
        $this->stdout("  Variant: {$stats['conversions']['variant']}\n\n");

        $this->stdout("Conversion Rates:\n", Console::BOLD);
        $this->stdout("  Control: {$stats['conversionRates']['control']}%\n");
        $this->stdout("  Variant: {$stats['conversionRates']['variant']}%\n\n");

        $this->stdout("Statistical Significance:\n", Console::BOLD);
        $confidence = number_format($stats['confidence'] * 100, 1);
        $significanceColor = $stats['isSignificant'] ? Console::FG_GREEN : Console::FG_YELLOW;
        $this->stdout("  Confidence: {$confidence}%\n", $significanceColor);
        $this->stdout("  Significant: " . ($stats['isSignificant'] ? 'Yes' : 'No') . "\n", $significanceColor);

        if ($stats['winner']) {
            $this->stdout("  Winner: " . ucfirst($stats['winner']) . "\n", Console::FG_GREEN);
        }

        if ($stats['improvement'] !== null) {
            $improvementStr = ($stats['improvement'] >= 0 ? '+' : '') . $stats['improvement'] . '%';
            $improvementColor = $stats['improvement'] >= 0 ? Console::FG_GREEN : Console::FG_RED;
            $this->stdout("  Improvement: {$improvementStr}\n", $improvementColor);
        }

        $this->stdout("\n");

        return ExitCode::OK;
    }

    /**
     * Recalculate and clear cached stats for a test
     *
     * Usage: ./craft abtestcraft/recalculate-stats <testId>
     *
     * @param int $testId The test ID to recalculate stats for
     */
    public function actionRecalculateStats(int $testId): int
    {
        $test = ABTestCraft::getInstance()->tests->getTestById($testId);

        if (!$test) {
            $this->stderr("Test not found: {$testId}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!$this->force) {
            $confirm = $this->confirm("Recalculate stats for test '{$test->name}'?");
            if (!$confirm) {
                $this->stdout("Aborted.\n");
                return ExitCode::OK;
            }
        }

        // Clear the stats cache
        ABTestCraft::getInstance()->stats->invalidateStatsCache($testId);

        // Force recalculation by fetching stats
        $stats = ABTestCraft::getInstance()->stats->getTestStats($test);

        $this->stdout("Stats cache cleared and recalculated for test: {$test->name}\n", Console::FG_GREEN);
        $this->stdout("  Impressions: {$stats['impressions']['control']} (control) / {$stats['impressions']['variant']} (variant)\n");
        $this->stdout("  Conversions: {$stats['conversions']['control']} (control) / {$stats['conversions']['variant']} (variant)\n");

        return ExitCode::OK;
    }

    /**
     * Clean up transient rate-limit data.
     *
     * Usage: ./craft abtestcraft/cleanup --days=90
     *
     * @return int
     */
    public function actionCleanup(): int
    {
        $this->stdout("\n");
        $this->stdout("ABTestCraft Cleanup\n", Console::BOLD);
        $this->stdout(str_repeat('-', 50) . "\n");
        $this->stdout("Visitor and conversion rows are retained because they are required for historical stats.\n");
        $this->stdout("Cleaning transient rate-limit records older than {$this->days} days...\n\n");

        if (!$this->force) {
            $confirm = $this->confirm("Proceed with cleanup?");
            if (!$confirm) {
                $this->stdout("Aborted.\n");
                return ExitCode::OK;
            }
        }

        $cutoffDate = (new \DateTime())->modify("-{$this->days} days")->format('Y-m-d H:i:s');
        $totalDeleted = Craft::$app->getDb()->createCommand()
            ->delete('{{%abtestcraft_rate_limits}}', ['<', 'windowStart', $cutoffDate])
            ->execute();

        $this->stdout("\n");
        if ($totalDeleted > 0) {
            $this->stdout("Total deleted: {$totalDeleted} rate-limit records\n", Console::FG_GREEN);
        } else {
            $this->stdout("No records needed cleanup.\n", Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    /**
     * Export test results to CSV
     *
     * Usage: ./craft abtestcraft/export <testId> [--output=/path/to/file.csv]
     *
     * @param int $testId The test ID to export
     */
    public function actionExport(int $testId): int
    {
        $test = ABTestCraft::getInstance()->tests->getTestById($testId);

        if (!$test) {
            $this->stderr("Test not found: {$testId}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $stats = ABTestCraft::getInstance()->stats->getTestStats($test);

        // Build CSV content
        $csv = "Metric,Control,Variant\n";
        $csv .= "Impressions,{$stats['impressions']['control']},{$stats['impressions']['variant']}\n";
        $csv .= "Conversions,{$stats['conversions']['control']},{$stats['conversions']['variant']}\n";
        $csv .= "Conversion Rate,{$stats['conversionRates']['control']}%,{$stats['conversionRates']['variant']}%\n";
        $csv .= "Visitors,{$stats['visitors']['control']},{$stats['visitors']['variant']}\n";

        $confidence = number_format($stats['confidence'] * 100, 1);
        $csv .= "\nStatistical Analysis\n";
        $csv .= "Confidence,{$confidence}%\n";
        $csv .= "Significant," . ($stats['isSignificant'] ? 'Yes' : 'No') . "\n";
        $csv .= "Winner," . ($stats['winner'] ?? 'None') . "\n";
        $csv .= "Improvement," . ($stats['improvement'] ?? 'N/A') . "%\n";

        // Output to stdout
        $this->stdout("\nExport for test: {$test->name}\n", Console::BOLD);
        $this->stdout(str_repeat('-', 50) . "\n");
        $this->stdout($csv);
        $this->stdout("\n");

        return ExitCode::OK;
    }
}
