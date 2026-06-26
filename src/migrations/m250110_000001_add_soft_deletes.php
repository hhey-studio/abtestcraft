<?php

declare(strict_types=1);

namespace livehand\abtestcraft\migrations;

use craft\db\Migration;

/**
 * Add soft delete support to tests table
 */
class m250110_000001_add_soft_deletes extends Migration
{
    public function safeUp(): bool
    {
        $table = $this->tableName('tests');

        // Add dateDeleted column for soft deletes
        if (!$this->db->columnExists($table, 'dateDeleted')) {
            $this->addColumn($table, 'dateDeleted', $this->dateTime()->null()->after('dateUpdated'));

            // Add index for efficient queries excluding deleted tests
            $this->createIndex(
                'idx_abtestcraft_tests_dateDeleted',
                $table,
                ['dateDeleted']
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        $table = $this->tableName('tests');

        // Remove index first. Craft 5 signature: dropIndexIfExists(table, columns)
        // — it locates the index by table + columns, not by name.
        $this->dropIndexIfExists($table, ['dateDeleted']);

        // Remove column
        if ($this->db->columnExists($table, 'dateDeleted')) {
            $this->dropColumn($table, 'dateDeleted');
        }

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
