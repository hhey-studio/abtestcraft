<?php

declare(strict_types=1);

namespace livehand\abtestcraft\migrations;

use craft\db\Migration;

/**
 * Fix unique index on daily_stats to include goalType
 * This allows multiple rows per day with different goal types
 */
class m250107_000002_fix_daily_stats_index extends Migration
{
    public function safeUp(): bool
    {
        $table = $this->tableName('daily_stats');

        // Get all indexes on the table
        $indexes = $this->db->getSchema()->getTableIndexes($table, true);

        foreach ($indexes as $index) {
            // Find the unique index on (testId, date, variant) without goalType
            $columns = $index->columnNames;
            if (
                $index->isUnique &&
                in_array('testId', $columns) &&
                in_array('date', $columns) &&
                in_array('variant', $columns) &&
                !in_array('goalType', $columns)
            ) {
                $this->dropIndex($index->name, $table);
                break;
            }
        }

        // Create new unique index including goalType
        $this->createIndex(
            'abtestcraft_daily_stats_unique',
            $table,
            ['testId', 'date', 'variant', 'goalType'],
            true
        );

        return true;
    }

    public function safeDown(): bool
    {
        $table = $this->tableName('daily_stats');

        // Drop the new index
        $this->dropIndex('abtestcraft_daily_stats_unique', $table);

        // Recreate the old index (without goalType)
        $this->createIndex(
            null,
            $table,
            ['testId', 'date', 'variant'],
            true
        );

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
