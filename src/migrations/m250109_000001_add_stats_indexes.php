<?php

declare(strict_types=1);

namespace livehand\abtestcraft\migrations;

use craft\db\Migration;

/**
 * Adds performance indexes to the daily_stats table
 */
class m250109_000001_add_stats_indexes extends Migration
{
    public function safeUp(): bool
    {
        $table = $this->tableName('daily_stats');

        // Add index for queries that filter by testId and variant
        $this->createIndex(
            'idx_abtestcraft_daily_stats_testId_variant',
            $table,
            ['testId', 'variant']
        );

        // Add index for queries that filter by testId and goalType
        $this->createIndex(
            'idx_abtestcraft_daily_stats_testId_goalType',
            $table,
            ['testId', 'goalType']
        );

        return true;
    }

    public function safeDown(): bool
    {
        $table = $this->tableName('daily_stats');

        $this->dropIndex('idx_abtestcraft_daily_stats_testId_variant', $table);
        $this->dropIndex('idx_abtestcraft_daily_stats_testId_goalType', $table);

        return true;
    }

    private function tableName(string $name): string
    {
        $oldTable = '{{%splittest_' . $name . '}}';
        return $this->db->tableExists($oldTable) && !$this->db->tableExists('{{%abtestcraft_' . $name . '}}')
            ? $oldTable
            : '{{%abtestcraft_' . $name . '}}';
    }
}
