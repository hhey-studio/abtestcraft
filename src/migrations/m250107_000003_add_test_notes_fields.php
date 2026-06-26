<?php

declare(strict_types=1);

namespace livehand\abtestcraft\migrations;

use craft\db\Migration;

/**
 * Add hypothesis, variant description, and learnings fields to tests
 */
class m250107_000003_add_test_notes_fields extends Migration
{
    public function safeUp(): bool
    {
        $table = $this->tableName('tests');

        // Add hypothesis field
        if (!$this->db->columnExists($table, 'hypothesis')) {
            $this->addColumn($table, 'hypothesis', $this->text()->null()->after('handle'));
        }

        // Add variant description field
        if (!$this->db->columnExists($table, 'variantDescription')) {
            $this->addColumn($table, 'variantDescription', $this->text()->null()->after('hypothesis'));
        }

        // Add learnings field (for post-test insights)
        if (!$this->db->columnExists($table, 'learnings')) {
            $this->addColumn($table, 'learnings', $this->text()->null()->after('variantDescription'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        $table = $this->tableName('tests');

        if ($this->db->columnExists($table, 'hypothesis')) {
            $this->dropColumn($table, 'hypothesis');
        }
        if ($this->db->columnExists($table, 'variantDescription')) {
            $this->dropColumn($table, 'variantDescription');
        }
        if ($this->db->columnExists($table, 'learnings')) {
            $this->dropColumn($table, 'learnings');
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
