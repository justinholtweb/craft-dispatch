<?php

namespace justinholtweb\dispatch\migrations;

use craft\db\Migration;

/**
 * Dispatch 5.2: drip sequences, A/B subject tests, dynamic segments and outgoing webhooks.
 */
class m261008_000000_sequences_ab_segments_integrations extends Migration
{
    use ProSchemaTrait;

    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%dispatch_segments}}')) {
            $this->createProSchema();
        }

        return true;
    }

    public function safeDown(): bool
    {
        echo "m261008_000000_sequences_ab_segments_integrations cannot be reverted.\n";

        return false;
    }
}
