<?php

declare(strict_types=1);

namespace livehand\abtestcraft\migrations;

use craft\db\Migration;

/**
 * m250110_000002_add_rate_limits_and_notifications migration
 *
 * Adds:
 * - Rate limits table for database-based rate limiting (multi-server support)
 * - Significance notification timestamp column to tests table
 */
class m250110_000002_add_rate_limits_and_notifications extends Migration
{
    public function safeUp(): bool
    {
        $prefix = $this->tablePrefix();
        $testsTable = '{{%' . $prefix . '_tests}}';
        $rateLimitsTable = '{{%' . $prefix . '_rate_limits}}';

        // Create rate limits table for database-based rate limiting
        if (!$this->db->tableExists($rateLimitsTable)) {
            $this->createTable($rateLimitsTable, [
                'id' => $this->primaryKey(),
                'cacheKey' => $this->string(255)->notNull(),
                'requestCount' => $this->integer()->notNull()->defaultValue(1),
                'windowStart' => $this->dateTime()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            // Unique index on cache key for upsert operations
            $this->createIndex(
                'idx_abtestcraft_rate_limits_cache_key',
                $rateLimitsTable,
                ['cacheKey'],
                true
            );

            // Index on window start for cleanup queries
            $this->createIndex(
                'idx_abtestcraft_rate_limits_window',
                $rateLimitsTable,
                ['windowStart']
            );
        }

        // Add significance notification timestamp to tests table
        if ($this->db->tableExists($testsTable) && !$this->db->columnExists($testsTable, 'significanceNotifiedAt')) {
            $this->addColumn(
                $testsTable,
                'significanceNotifiedAt',
                $this->dateTime()->null()->after('winnerVariant')
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        $rateLimitsTable = $this->tableName('rate_limits');
        $testsTable = $this->tableName('tests');

        // Drop rate limits table
        $this->dropTableIfExists($rateLimitsTable);

        // Remove significance notification column
        if ($this->db->tableExists($testsTable) && $this->db->columnExists($testsTable, 'significanceNotifiedAt')) {
            $this->dropColumn($testsTable, 'significanceNotifiedAt');
        }

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
