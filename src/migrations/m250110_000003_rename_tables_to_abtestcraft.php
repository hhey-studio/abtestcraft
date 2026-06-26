<?php

declare(strict_types=1);

namespace livehand\abtestcraft\migrations;

use craft\db\Migration;

/**
 * Migration to rename all plugin tables from splittest_* to abtestcraft_*
 * This is part of the plugin rebrand from "Split Test" to "ABTestCraft"
 */
class m250110_000003_rename_tables_to_abtestcraft extends Migration
{
    public function safeUp(): bool
    {
        // Rename all tables from splittest_* to abtestcraft_*
        $tables = [
            'splittest_tests' => 'abtestcraft_tests',
            'splittest_goals' => 'abtestcraft_goals',
            'splittest_visitors' => 'abtestcraft_visitors',
            'splittest_daily_stats' => 'abtestcraft_daily_stats',
            'splittest_rate_limits' => 'abtestcraft_rate_limits',
            'splittest_test_descendants' => 'abtestcraft_test_descendants',
        ];

        foreach ($tables as $oldName => $newName) {
            $oldTable = '{{%' . $oldName . '}}';
            $newTable = '{{%' . $newName . '}}';

            // Only rename when the source exists and the target doesn't, so this
            // is a safe no-op on fresh installs (which already have the new names).
            if ($this->db->tableExists($oldTable) && !$this->db->tableExists($newTable)) {
                $this->renameTable($oldTable, $newTable);
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        // Rename tables back to original names
        $tables = [
            'abtestcraft_tests' => 'splittest_tests',
            'abtestcraft_goals' => 'splittest_goals',
            'abtestcraft_visitors' => 'splittest_visitors',
            'abtestcraft_daily_stats' => 'splittest_daily_stats',
            'abtestcraft_rate_limits' => 'splittest_rate_limits',
            'abtestcraft_test_descendants' => 'splittest_test_descendants',
        ];

        foreach ($tables as $oldName => $newName) {
            $oldTable = '{{%' . $oldName . '}}';
            $newTable = '{{%' . $newName . '}}';

            if ($this->db->tableExists($oldTable) && !$this->db->tableExists($newTable)) {
                $this->renameTable($oldTable, $newTable);
            }
        }

        return true;
    }
}
