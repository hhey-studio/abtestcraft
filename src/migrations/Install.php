<?php

declare(strict_types=1);

namespace livehand\abtestcraft\migrations;

use Craft;
use craft\db\Migration;

/**
 * Install migration for Split Test plugin
 *
 * This migration creates the complete schema for fresh installs.
 * It includes all tables and columns from incremental migrations:
 * - m250107_000001_add_goals_table (goals table)
 * - m250107_000003_add_test_notes_fields (hypothesis, variantDescription, learnings)
 * - m250110_000001_add_cascade_descendants_table (test_descendants table)
 * - m250110_000001_add_soft_deletes (dateDeleted column)
 * - m250110_000002_add_rate_limits_and_notifications (rate_limits table, significanceNotifiedAt)
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropForeignKeys();
        $this->dropTables();

        return true;
    }

    protected function createTables(): void
    {
        // Tests table
        $this->createTable('{{%abtestcraft_tests}}', [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull(),
            'name' => $this->string(255)->notNull(),
            'handle' => $this->string(255)->notNull(),
            'hypothesis' => $this->text()->null(), // From m250107_000003
            'variantDescription' => $this->text()->null(), // From m250107_000003
            'learnings' => $this->text()->null(), // From m250107_000003
            'status' => $this->string(20)->notNull()->defaultValue('draft'), // draft, running, paused, completed
            'controlEntryId' => $this->integer()->notNull(),
            'variantEntryId' => $this->integer()->notNull(),
            'trafficSplit' => $this->integer()->notNull()->defaultValue(50), // 0-100 percentage to variant
            'goalType' => $this->string(50)->notNull(), // phone, form, page, email, download
            'goalValue' => $this->string(500)->null(), // URL for page visit goal, or file extension patterns
            'startedAt' => $this->dateTime()->null(),
            'endedAt' => $this->dateTime()->null(),
            'winnerVariant' => $this->string(20)->null(), // control, variant, or null
            'significanceNotifiedAt' => $this->dateTime()->null(), // From m250110_000002
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'dateDeleted' => $this->dateTime()->null(), // From m250110_000001 (soft deletes)
            'uid' => $this->uid(),
        ]);

        // Goals table (from m250107_000001)
        $this->createTable('{{%abtestcraft_goals}}', [
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

        // Visitors table
        $this->createTable('{{%abtestcraft_visitors}}', [
            'id' => $this->primaryKey(),
            'testId' => $this->integer()->notNull(),
            'visitorId' => $this->string(36)->notNull(), // UUID from cookie
            'variant' => $this->string(20)->notNull(), // control, variant
            'converted' => $this->boolean()->notNull()->defaultValue(false),
            'conversionType' => $this->string(50)->null(), // phone, form, page, email, download
            'goalId' => $this->integer()->null(), // From m250107_000001 - references goal for goal-specific conversions
            'dateConverted' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Daily stats table (aggregates for fast reporting)
        $this->createTable('{{%abtestcraft_daily_stats}}', [
            'id' => $this->primaryKey(),
            'testId' => $this->integer()->notNull(),
            'date' => $this->date()->notNull(),
            'variant' => $this->string(20)->notNull(), // control, variant
            'goalType' => $this->string(50)->null(), // From m250107_000001 - phone, form, page, email, download (null for aggregate stats)
            'impressions' => $this->integer()->notNull()->defaultValue(0),
            'conversions' => $this->integer()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Rate limits table (from m250110_000002)
        $this->createTable('{{%abtestcraft_rate_limits}}', [
            'id' => $this->primaryKey(),
            'cacheKey' => $this->string(255)->notNull(),
            'requestCount' => $this->integer()->notNull()->defaultValue(1),
            'windowStart' => $this->dateTime()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Conversion events table (from m250626_000001)
        $this->createTable('{{%abtestcraft_conversions}}', [
            'id' => $this->primaryKey(),
            'testId' => $this->integer()->notNull(),
            'visitorId' => $this->string(36)->notNull(),
            'variant' => $this->string(20)->notNull(),
            'conversionType' => $this->string(50)->notNull(),
            'goalId' => $this->integer()->null(),
            'dateConverted' => $this->dateTime()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Test descendants table for cascade support (from m250110_000001_add_cascade_descendants_table)
        $this->createTable('{{%abtestcraft_test_descendants}}', [
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
    }

    protected function createIndexes(): void
    {
        // Tests indexes
        $this->createIndex(null, '{{%abtestcraft_tests}}', ['siteId']);
        $this->createIndex(null, '{{%abtestcraft_tests}}', ['handle'], true);
        $this->createIndex(null, '{{%abtestcraft_tests}}', ['status']);
        $this->createIndex(null, '{{%abtestcraft_tests}}', ['controlEntryId']);
        $this->createIndex(null, '{{%abtestcraft_tests}}', ['variantEntryId']);
        $this->createIndex('idx_abtestcraft_tests_dateDeleted', '{{%abtestcraft_tests}}', ['dateDeleted']);

        // Goals indexes (from m250107_000001)
        $this->createIndex(null, '{{%abtestcraft_goals}}', ['testId']);
        $this->createIndex(null, '{{%abtestcraft_goals}}', ['goalType']);
        $this->createIndex(null, '{{%abtestcraft_goals}}', ['testId', 'goalType'], true);

        // Visitors indexes
        $this->createIndex(null, '{{%abtestcraft_visitors}}', ['testId']);
        $this->createIndex(null, '{{%abtestcraft_visitors}}', ['visitorId']);
        $this->createIndex(null, '{{%abtestcraft_visitors}}', ['testId', 'visitorId'], true);
        $this->createIndex(null, '{{%abtestcraft_visitors}}', ['converted']);

        // Daily stats indexes
        $this->createIndex(null, '{{%abtestcraft_daily_stats}}', ['testId']);
        // Include goalType in unique index to allow multiple goal types per day
        $this->createIndex(null, '{{%abtestcraft_daily_stats}}', ['testId', 'date', 'variant', 'goalType'], true);

        // Rate limits indexes (from m250110_000002)
        $this->createIndex('idx_abtestcraft_rate_limits_cache_key', '{{%abtestcraft_rate_limits}}', ['cacheKey'], true);
        $this->createIndex('idx_abtestcraft_rate_limits_window', '{{%abtestcraft_rate_limits}}', ['windowStart']);

        // Conversion event indexes
        $this->createIndex(null, '{{%abtestcraft_conversions}}', ['testId']);
        $this->createIndex(null, '{{%abtestcraft_conversions}}', ['testId', 'visitorId']);
        $this->createIndex(null, '{{%abtestcraft_conversions}}', ['testId', 'conversionType']);
        $this->createIndex(null, '{{%abtestcraft_conversions}}', ['testId', 'goalId']);
        $this->createIndex(null, '{{%abtestcraft_conversions}}', ['dateConverted']);
        // Test descendants indexes (from m250110_000001_add_cascade_descendants_table)
        $this->createIndex(null, '{{%abtestcraft_test_descendants}}', ['testId']);
        $this->createIndex(null, '{{%abtestcraft_test_descendants}}', ['descendantEntryId']);
        $this->createIndex(null, '{{%abtestcraft_test_descendants}}', ['controlEntryId']);
        $this->createIndex(null, '{{%abtestcraft_test_descendants}}', ['siteId']);
        $this->createIndex(null, '{{%abtestcraft_test_descendants}}', ['testId', 'descendantEntryId'], true);
    }

    protected function addForeignKeys(): void
    {
        // Tests foreign keys
        $this->addForeignKey(
            null,
            '{{%abtestcraft_tests}}',
            ['siteId'],
            '{{%sites}}',
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        $this->addForeignKey(
            null,
            '{{%abtestcraft_tests}}',
            ['controlEntryId'],
            '{{%elements}}',
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        $this->addForeignKey(
            null,
            '{{%abtestcraft_tests}}',
            ['variantEntryId'],
            '{{%elements}}',
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        // Goals foreign keys (from m250107_000001)
        $this->addForeignKey(
            null,
            '{{%abtestcraft_goals}}',
            ['testId'],
            '{{%abtestcraft_tests}}',
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        // Visitors foreign keys
        $this->addForeignKey(
            null,
            '{{%abtestcraft_visitors}}',
            ['testId'],
            '{{%abtestcraft_tests}}',
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        // Daily stats foreign keys
        $this->addForeignKey(
            null,
            '{{%abtestcraft_daily_stats}}',
            ['testId'],
            '{{%abtestcraft_tests}}',
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        // Conversion events foreign keys
        $this->addForeignKey(
            null,
            '{{%abtestcraft_conversions}}',
            ['testId'],
            '{{%abtestcraft_tests}}',
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        // Test descendants foreign keys (from m250110_000001_add_cascade_descendants_table)
        $this->addForeignKey(
            null,
            '{{%abtestcraft_test_descendants}}',
            ['testId'],
            '{{%abtestcraft_tests}}',
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        $this->addForeignKey(
            null,
            '{{%abtestcraft_test_descendants}}',
            ['controlEntryId'],
            '{{%elements}}',
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        $this->addForeignKey(
            null,
            '{{%abtestcraft_test_descendants}}',
            ['descendantEntryId'],
            '{{%elements}}',
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        $this->addForeignKey(
            null,
            '{{%abtestcraft_test_descendants}}',
            ['variantAncestorId'],
            '{{%elements}}',
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        $this->addForeignKey(
            null,
            '{{%abtestcraft_test_descendants}}',
            ['siteId'],
            '{{%sites}}',
            ['id'],
            'CASCADE',
            'CASCADE'
        );
    }

    protected function dropForeignKeys(): void
    {
        // Drop in reverse order of creation
        if ($this->db->tableExists('{{%abtestcraft_test_descendants}}')) {
            $this->dropAllForeignKeysToTable('{{%abtestcraft_test_descendants}}');
        }
        if ($this->db->tableExists('{{%abtestcraft_daily_stats}}')) {
            $this->dropAllForeignKeysToTable('{{%abtestcraft_daily_stats}}');
        }
        if ($this->db->tableExists('{{%abtestcraft_conversions}}')) {
            $this->dropAllForeignKeysToTable('{{%abtestcraft_conversions}}');
        }
        if ($this->db->tableExists('{{%abtestcraft_visitors}}')) {
            $this->dropAllForeignKeysToTable('{{%abtestcraft_visitors}}');
        }
        if ($this->db->tableExists('{{%abtestcraft_goals}}')) {
            $this->dropAllForeignKeysToTable('{{%abtestcraft_goals}}');
        }
        if ($this->db->tableExists('{{%abtestcraft_tests}}')) {
            $this->dropAllForeignKeysToTable('{{%abtestcraft_tests}}');
        }
    }

    protected function dropTables(): void
    {
        $this->dropTableIfExists('{{%abtestcraft_test_descendants}}');
        $this->dropTableIfExists('{{%abtestcraft_rate_limits}}');
        $this->dropTableIfExists('{{%abtestcraft_conversions}}');
        $this->dropTableIfExists('{{%abtestcraft_daily_stats}}');
        $this->dropTableIfExists('{{%abtestcraft_visitors}}');
        $this->dropTableIfExists('{{%abtestcraft_goals}}');
        $this->dropTableIfExists('{{%abtestcraft_tests}}');
    }
}
