<?php

declare(strict_types=1);

namespace livehand\abtestcraft\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\StringHelper;

/**
 * Add durable conversion events for per-goal duplicate detection and reporting.
 */
class m250626_000001_add_conversions_table extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists('{{%abtestcraft_conversions}}')) {
            $this->backfillExistingConversions();
            return true;
        }

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

        $this->createIndex(null, '{{%abtestcraft_conversions}}', ['testId']);
        $this->createIndex(null, '{{%abtestcraft_conversions}}', ['testId', 'visitorId']);
        $this->createIndex(null, '{{%abtestcraft_conversions}}', ['testId', 'conversionType']);
        $this->createIndex(null, '{{%abtestcraft_conversions}}', ['testId', 'goalId']);
        $this->createIndex(null, '{{%abtestcraft_conversions}}', ['dateConverted']);
        $this->addForeignKey(
            null,
            '{{%abtestcraft_conversions}}',
            ['testId'],
            '{{%abtestcraft_tests}}',
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        $this->backfillExistingConversions();

        return true;
    }

    private function backfillExistingConversions(): void
    {
        if (!$this->db->tableExists('{{%abtestcraft_visitors}}')) {
            return;
        }

        $rows = (new Query())
            ->select(['testId', 'visitorId', 'variant', 'conversionType', 'goalId', 'dateConverted'])
            ->from('{{%abtestcraft_visitors}}')
            ->where(['converted' => true])
            ->all();

        foreach ($rows as $row) {
            $conversionType = $row['conversionType'] ?: 'custom';
            $alreadyBackfilled = (new Query())
                ->from('{{%abtestcraft_conversions}}')
                ->where([
                    'testId' => $row['testId'],
                    'visitorId' => $row['visitorId'],
                    'conversionType' => $conversionType,
                ])
                ->exists();

            if ($alreadyBackfilled) {
                continue;
            }

            $now = (new \DateTime())->format('Y-m-d H:i:s');
            Craft::$app->getDb()->createCommand()
                ->insert('{{%abtestcraft_conversions}}', [
                    'testId' => $row['testId'],
                    'visitorId' => $row['visitorId'],
                    'variant' => $row['variant'],
                    'conversionType' => $conversionType,
                    'goalId' => $row['goalId'] ?? null,
                    'dateConverted' => $row['dateConverted'] ?: $now,
                    'dateCreated' => $now,
                    'dateUpdated' => $now,
                    'uid' => StringHelper::UUID(),
                ])
                ->execute();
        }
    }

    public function safeDown(): bool
    {
        if ($this->db->tableExists('{{%abtestcraft_conversions}}')) {
            $this->dropAllForeignKeysToTable('{{%abtestcraft_conversions}}');
        }
        $this->dropTableIfExists('{{%abtestcraft_conversions}}');

        return true;
    }
}
