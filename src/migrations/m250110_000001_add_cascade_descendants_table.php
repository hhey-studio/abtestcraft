<?php

declare(strict_types=1);

namespace livehand\abtestcraft\migrations;

use craft\db\Migration;

/**
 * Migration to add cascade descendants table for nested entry support
 */
class m250110_000001_add_cascade_descendants_table extends Migration
{
    public function safeUp(): bool
    {
        $prefix = $this->tablePrefix();
        $testsTable = '{{%' . $prefix . '_tests}}';
        $descendantsTable = '{{%' . $prefix . '_test_descendants}}';

        // Create the test descendants table
        if (!$this->db->tableExists($descendantsTable)) {
            $this->createTable($descendantsTable, [
                'id' => $this->primaryKey(),
                'testId' => $this->integer()->notNull(),
                'controlEntryId' => $this->integer()->notNull(),
                'descendantEntryId' => $this->integer()->notNull(),
                'variantAncestorId' => $this->integer()->notNull(),
                'depth' => $this->integer()->notNull()->defaultValue(1),
                'siteId' => $this->integer()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            // Create indexes
            $this->createIndex(null, $descendantsTable, ['testId']);
            $this->createIndex(null, $descendantsTable, ['descendantEntryId']);
            $this->createIndex(null, $descendantsTable, ['controlEntryId']);
            $this->createIndex(null, $descendantsTable, ['siteId']);
            $this->createIndex(null, $descendantsTable, ['testId', 'descendantEntryId'], true);

            // Add foreign keys
            $this->addForeignKey(
                null,
                $descendantsTable,
                ['testId'],
                $testsTable,
                ['id'],
                'CASCADE',
                'CASCADE'
            );

            $this->addForeignKey(
                null,
                $descendantsTable,
                ['controlEntryId'],
                '{{%elements}}',
                ['id'],
                'CASCADE',
                'CASCADE'
            );

            $this->addForeignKey(
                null,
                $descendantsTable,
                ['descendantEntryId'],
                '{{%elements}}',
                ['id'],
                'CASCADE',
                'CASCADE'
            );

            $this->addForeignKey(
                null,
                $descendantsTable,
                ['variantAncestorId'],
                '{{%elements}}',
                ['id'],
                'CASCADE',
                'CASCADE'
            );

            $this->addForeignKey(
                null,
                $descendantsTable,
                ['siteId'],
                '{{%sites}}',
                ['id'],
                'CASCADE',
                'CASCADE'
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        $descendantsTable = $this->tableName('test_descendants');

        // Drop foreign keys first
        if ($this->db->tableExists($descendantsTable)) {
            $this->dropAllForeignKeysToTable($descendantsTable);
        }

        // Drop the table
        $this->dropTableIfExists($descendantsTable);

        return true;
    }

    private function tablePrefix(): string
    {
        return $this->db->tableExists('{{%splittest_tests}}') && !$this->db->tableExists('{{%abtestcraft_tests}}')
            ? 'splittest'
            : 'abtestcraft';
    }

    private function tableName(string $name): string
    {
        $oldTable = '{{%splittest_' . $name . '}}';
        return $this->db->tableExists($oldTable) && !$this->db->tableExists('{{%abtestcraft_' . $name . '}}')
            ? $oldTable
            : '{{%abtestcraft_' . $name . '}}';
    }
}
