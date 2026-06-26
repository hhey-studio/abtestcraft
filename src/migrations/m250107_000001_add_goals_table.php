<?php

declare(strict_types=1);

namespace livehand\abtestcraft\migrations;

use craft\db\Migration;

/**
 * Add goals table for multi-goal conversion tracking
 */
class m250107_000001_add_goals_table extends Migration
{
    public function safeUp(): bool
    {
        $prefix = $this->tablePrefix();
        $testsTable = '{{%' . $prefix . '_tests}}';
        $goalsTable = '{{%' . $prefix . '_goals}}';
        $dailyStatsTable = '{{%' . $prefix . '_daily_stats}}';
        $visitorsTable = '{{%' . $prefix . '_visitors}}';

        // Create goals table
        if (!$this->db->tableExists($goalsTable)) {
            $this->createTable($goalsTable, [
                'id' => $this->primaryKey(),
                'testId' => $this->integer()->notNull(),
                'goalType' => $this->string(50)->notNull(), // form, phone, email, download, page, custom
                'isEnabled' => $this->boolean()->notNull()->defaultValue(true),
                'config' => $this->json()->null(), // Flexible config per goal type
                'sortOrder' => $this->smallInteger()->unsigned()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            // Add indexes
            $this->createIndex(null, $goalsTable, ['testId']);
            $this->createIndex(null, $goalsTable, ['goalType']);
            $this->createIndex(null, $goalsTable, ['testId', 'goalType'], true);

            // Add foreign key
            $this->addForeignKey(
                null,
                $goalsTable,
                ['testId'],
                $testsTable,
                ['id'],
                'CASCADE',
                'CASCADE'
            );
        }

        // Add goalType column to daily_stats if not exists (for per-goal stats)
        if ($this->db->tableExists($dailyStatsTable) && !$this->db->columnExists($dailyStatsTable, 'goalType')) {
            $this->addColumn(
                $dailyStatsTable,
                'goalType',
                $this->string(50)->null()->after('conversions')
            );
        }

        // Add goalType column to visitors for tracking which goal converted
        if ($this->db->tableExists($visitorsTable) && !$this->db->columnExists($visitorsTable, 'goalId')) {
            $this->addColumn(
                $visitorsTable,
                'goalId',
                $this->integer()->null()->after('conversionType')
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        $prefix = $this->tablePrefix();
        $goalsTable = '{{%' . $prefix . '_goals}}';
        $dailyStatsTable = '{{%' . $prefix . '_daily_stats}}';
        $visitorsTable = '{{%' . $prefix . '_visitors}}';

        // Remove columns first
        if ($this->db->tableExists($visitorsTable) && $this->db->columnExists($visitorsTable, 'goalId')) {
            $this->dropColumn($visitorsTable, 'goalId');
        }
        if ($this->db->tableExists($dailyStatsTable) && $this->db->columnExists($dailyStatsTable, 'goalType')) {
            $this->dropColumn($dailyStatsTable, 'goalType');
        }

        // Drop goals table
        $this->dropTableIfExists($goalsTable);

        return true;
    }

    private function tablePrefix(): string
    {
        return $this->db->tableExists('{{%splittest_tests}}') && !$this->db->tableExists('{{%abtestcraft_tests}}')
            ? 'splittest'
            : 'abtestcraft';
    }
}
