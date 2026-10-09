<?php

namespace justinholtweb\dispatch\migrations;

/**
 * The 5.2 tables and columns — drip sequences, A/B subject tests, dynamic segments and outgoing
 * webhooks — shared by `Install` and the upgrade migration so the two cannot drift.
 *
 * @mixin \craft\db\Migration
 */
trait ProSchemaTrait
{
    protected function createProSchema(): void
    {
        // Dynamic segments: a saved Subscriber condition, evaluated when a campaign sends.
        $this->createTable('{{%dispatch_segments}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string(255)->notNull(),
            'handle' => $this->string(255)->notNull(),
            'description' => $this->text()->null(),
            'condition' => $this->text()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, '{{%dispatch_segments}}', ['handle'], true);

        // Campaign audience by segment, and the A/B subject test.
        if (!$this->db->columnExists('{{%dispatch_campaigns}}', 'segmentId')) {
            $this->addColumn('{{%dispatch_campaigns}}', 'segmentId', $this->integer()->null()->after('mailingListId'));
            $this->addColumn('{{%dispatch_campaigns}}', 'abEnabled', $this->boolean()->notNull()->defaultValue(false));
            $this->addColumn('{{%dispatch_campaigns}}', 'abSubjects', $this->text()->null());
            $this->addColumn('{{%dispatch_campaigns}}', 'abTestPercent', $this->smallInteger()->notNull()->defaultValue(20));
            $this->addColumn('{{%dispatch_campaigns}}', 'abMetric', $this->string(10)->notNull()->defaultValue('opens'));
            $this->addColumn('{{%dispatch_campaigns}}', 'abWaitMinutes', $this->integer()->notNull()->defaultValue(240));
            $this->addColumn('{{%dispatch_campaigns}}', 'abStatus', $this->string(20)->null());
            $this->addColumn('{{%dispatch_campaigns}}', 'abWinner', $this->smallInteger()->null());
            $this->addColumn('{{%dispatch_campaigns}}', 'abDecideAt', $this->dateTime()->null());
        }
        $this->createIndex(null, '{{%dispatch_campaigns}}', ['abStatus', 'abDecideAt']);
        $this->addForeignKey(null, '{{%dispatch_campaigns}}', ['segmentId'], '{{%dispatch_segments}}', ['id'], 'SET NULL', null);

        // Which subject variant each recipient got (A/B test sends only; null otherwise).
        if (!$this->db->columnExists('{{%dispatch_sendlog}}', 'variant')) {
            $this->addColumn('{{%dispatch_sendlog}}', 'variant', $this->smallInteger()->null());
        }

        // Drip sequences.
        $this->createTable('{{%dispatch_sequences}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string(255)->notNull(),
            'handle' => $this->string(255)->notNull(),
            'triggerType' => $this->string(20)->notNull()->defaultValue('list'),
            'triggerListId' => $this->integer()->null(),
            'triggerEvent' => $this->string(255)->null(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, '{{%dispatch_sequences}}', ['handle'], true);
        $this->createIndex(null, '{{%dispatch_sequences}}', ['triggerType', 'triggerListId']);
        $this->addForeignKey(null, '{{%dispatch_sequences}}', ['triggerListId'], '{{%dispatch_mailinglists}}', ['id'], 'SET NULL', null);

        $this->createTable('{{%dispatch_sequencesteps}}', [
            'id' => $this->primaryKey(),
            'sequenceId' => $this->integer()->notNull(),
            'campaignId' => $this->integer()->notNull(),
            'sortOrder' => $this->smallInteger()->notNull()->defaultValue(0),
            'delayMinutes' => $this->integer()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, '{{%dispatch_sequencesteps}}', ['sequenceId', 'sortOrder']);
        $this->addForeignKey(null, '{{%dispatch_sequencesteps}}', ['sequenceId'], '{{%dispatch_sequences}}', ['id'], 'CASCADE', null);
        $this->addForeignKey(null, '{{%dispatch_sequencesteps}}', ['campaignId'], '{{%dispatch_campaigns}}', ['id'], 'CASCADE', null);

        // One enrolment per subscriber per sequence, ever.
        $this->createTable('{{%dispatch_enrolments}}', [
            'id' => $this->primaryKey(),
            'sequenceId' => $this->integer()->notNull(),
            'subscriberId' => $this->integer()->notNull(),
            'status' => $this->string(20)->notNull()->defaultValue('active'),
            'stopReason' => $this->string(255)->null(),
            'enrolledAt' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, '{{%dispatch_enrolments}}', ['sequenceId', 'subscriberId'], true);
        $this->createIndex(null, '{{%dispatch_enrolments}}', ['subscriberId', 'status']);
        $this->addForeignKey(null, '{{%dispatch_enrolments}}', ['sequenceId'], '{{%dispatch_sequences}}', ['id'], 'CASCADE', null);
        $this->addForeignKey(null, '{{%dispatch_enrolments}}', ['subscriberId'], '{{%dispatch_subscribers}}', ['id'], 'CASCADE', null);

        // Each enrolment's steps, materialised with the time they fall due.
        $this->createTable('{{%dispatch_sequencesends}}', [
            'id' => $this->primaryKey(),
            'enrolmentId' => $this->integer()->notNull(),
            'stepId' => $this->integer()->notNull(),
            'subscriberId' => $this->integer()->notNull(),
            'campaignId' => $this->integer()->notNull(),
            'dueAt' => $this->dateTime()->notNull(),
            'status' => $this->string(20)->notNull()->defaultValue('pending'),
            'sentAt' => $this->dateTime()->null(),
            'error' => $this->text()->null(),
        ]);
        $this->createIndex(null, '{{%dispatch_sequencesends}}', ['enrolmentId', 'stepId'], true);
        $this->createIndex(null, '{{%dispatch_sequencesends}}', ['status', 'dueAt']);
        $this->addForeignKey(null, '{{%dispatch_sequencesends}}', ['enrolmentId'], '{{%dispatch_enrolments}}', ['id'], 'CASCADE', null);
        $this->addForeignKey(null, '{{%dispatch_sequencesends}}', ['stepId'], '{{%dispatch_sequencesteps}}', ['id'], 'CASCADE', null);
        $this->addForeignKey(null, '{{%dispatch_sequencesends}}', ['subscriberId'], '{{%dispatch_subscribers}}', ['id'], 'CASCADE', null);
        $this->addForeignKey(null, '{{%dispatch_sequencesends}}', ['campaignId'], '{{%dispatch_campaigns}}', ['id'], 'CASCADE', null);

        // Outgoing webhooks ("integrations"): endpoints and their delivery log. Not the incoming
        // bounce webhooks, which have no table.
        $this->createTable('{{%dispatch_endpoints}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string(255)->notNull(),
            'url' => $this->string(2048)->notNull(),
            'secret' => $this->string(255)->notNull(),
            'events' => $this->text()->null(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%dispatch_deliveries}}', [
            'id' => $this->primaryKey(),
            'endpointId' => $this->integer()->notNull(),
            'event' => $this->string(50)->notNull(),
            'payload' => $this->mediumText()->notNull(),
            'status' => $this->string(20)->notNull()->defaultValue('pending'),
            'attempts' => $this->smallInteger()->notNull()->defaultValue(0),
            'responseCode' => $this->smallInteger()->null(),
            'responseBody' => $this->text()->null(),
            'error' => $this->text()->null(),
            'deliveredAt' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, '{{%dispatch_deliveries}}', ['endpointId', 'dateCreated']);
        $this->createIndex(null, '{{%dispatch_deliveries}}', ['status']);
        $this->addForeignKey(null, '{{%dispatch_deliveries}}', ['endpointId'], '{{%dispatch_endpoints}}', ['id'], 'CASCADE', null);
    }

    protected function dropProSchema(): void
    {
        $this->dropTableIfExists('{{%dispatch_deliveries}}');
        $this->dropTableIfExists('{{%dispatch_endpoints}}');
        $this->dropTableIfExists('{{%dispatch_sequencesends}}');
        $this->dropTableIfExists('{{%dispatch_enrolments}}');
        $this->dropTableIfExists('{{%dispatch_sequencesteps}}');
        $this->dropTableIfExists('{{%dispatch_sequences}}');
    }
}
