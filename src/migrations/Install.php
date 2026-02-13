<?php

namespace justinholtweb\dispatch\migrations;

use craft\db\Migration;

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
        $this->dropTableIfExists('{{%dispatch_tracking}}');
        $this->dropTableIfExists('{{%dispatch_sendlog}}');
        $this->dropTableIfExists('{{%dispatch_subscriptions}}');
        $this->dropTableIfExists('{{%dispatch_campaigns}}');
        $this->dropTableIfExists('{{%dispatch_mailinglists}}');
        $this->dropTableIfExists('{{%dispatch_subscribers}}');

        return true;
    }

    private function createTables(): void
    {
        // Subscribers
        $this->createTable('{{%dispatch_subscribers}}', [
            'id' => $this->integer()->notNull(),
            'email' => $this->string(255)->notNull(),
            'firstName' => $this->string(255)->null(),
            'lastName' => $this->string(255)->null(),
            'status' => $this->string(20)->notNull()->defaultValue('active'),
            'userId' => $this->integer()->null(),
            'subscribedAt' => $this->dateTime()->notNull(),
            'unsubscribedAt' => $this->dateTime()->null(),
            'metadata' => $this->json()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        // Campaigns
        $this->createTable('{{%dispatch_campaigns}}', [
            'id' => $this->integer()->notNull(),
            'subject' => $this->string(255)->notNull(),
            'fromName' => $this->string(255)->null(),
            'fromEmail' => $this->string(255)->null(),
            'replyToEmail' => $this->string(255)->null(),
            'templatePath' => $this->string(255)->null(),
            'body' => $this->text()->null(),
            'campaignStatus' => $this->string(20)->notNull()->defaultValue('draft'),
            'mailingListId' => $this->integer()->null(),
            'scheduledAt' => $this->dateTime()->null(),
            'sentAt' => $this->dateTime()->null(),
            'totalRecipients' => $this->integer()->notNull()->defaultValue(0),
            'totalSent' => $this->integer()->notNull()->defaultValue(0),
            'totalFailed' => $this->integer()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        // Mailing Lists
        $this->createTable('{{%dispatch_mailinglists}}', [
            'id' => $this->integer()->notNull(),
            'name' => $this->string(255)->notNull(),
            'handle' => $this->string(255)->notNull(),
            'description' => $this->text()->null(),
            'subscriberCount' => $this->integer()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        // Subscriptions (pivot)
        $this->createTable('{{%dispatch_subscriptions}}', [
            'id' => $this->primaryKey(),
            'subscriberId' => $this->integer()->notNull(),
            'mailingListId' => $this->integer()->notNull(),
            'subscribedAt' => $this->dateTime()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Send Log
        $this->createTable('{{%dispatch_sendlog}}', [
            'id' => $this->primaryKey(),
            'campaignId' => $this->integer()->notNull(),
            'subscriberId' => $this->integer()->notNull(),
            'status' => $this->string(20)->notNull()->defaultValue('sent'),
            'errorMessage' => $this->text()->null(),
            'sentAt' => $this->dateTime()->notNull(),
            'messageId' => $this->string(255)->null(),
        ]);

        // Tracking (Lite+)
        $this->createTable('{{%dispatch_tracking}}', [
            'id' => $this->primaryKey(),
            'campaignId' => $this->integer()->notNull(),
            'subscriberId' => $this->integer()->notNull(),
            'type' => $this->string(20)->notNull(),
            'url' => $this->string(2048)->null(),
            'ipAddress' => $this->string(45)->null(),
            'userAgent' => $this->string(255)->null(),
            'trackedAt' => $this->dateTime()->notNull(),
        ]);
    }

    private function createIndexes(): void
    {
        // Subscribers
        $this->createIndex(null, '{{%dispatch_subscribers}}', ['email'], true);
        $this->createIndex(null, '{{%dispatch_subscribers}}', ['userId']);
        $this->createIndex(null, '{{%dispatch_subscribers}}', ['status']);

        // Campaigns
        $this->createIndex(null, '{{%dispatch_campaigns}}', ['campaignStatus']);
        $this->createIndex(null, '{{%dispatch_campaigns}}', ['mailingListId']);
        $this->createIndex(null, '{{%dispatch_campaigns}}', ['scheduledAt']);

        // Mailing Lists
        $this->createIndex(null, '{{%dispatch_mailinglists}}', ['handle'], true);

        // Subscriptions
        $this->createIndex(null, '{{%dispatch_subscriptions}}', ['subscriberId', 'mailingListId'], true);

        // Send Log
        $this->createIndex(null, '{{%dispatch_sendlog}}', ['campaignId', 'subscriberId'], true);
        $this->createIndex(null, '{{%dispatch_sendlog}}', ['messageId']);

        // Tracking
        $this->createIndex(null, '{{%dispatch_tracking}}', ['campaignId']);
        $this->createIndex(null, '{{%dispatch_tracking}}', ['subscriberId']);
        $this->createIndex(null, '{{%dispatch_tracking}}', ['type']);
    }

    private function addForeignKeys(): void
    {
        // Subscribers → elements
        $this->addForeignKey(null, '{{%dispatch_subscribers}}', ['id'], '{{%elements}}', ['id'], 'CASCADE', null);
        $this->addForeignKey(null, '{{%dispatch_subscribers}}', ['userId'], '{{%users}}', ['id'], 'SET NULL', null);

        // Campaigns → elements
        $this->addForeignKey(null, '{{%dispatch_campaigns}}', ['id'], '{{%elements}}', ['id'], 'CASCADE', null);
        $this->addForeignKey(null, '{{%dispatch_campaigns}}', ['mailingListId'], '{{%dispatch_mailinglists}}', ['id'], 'SET NULL', null);

        // Mailing Lists → elements
        $this->addForeignKey(null, '{{%dispatch_mailinglists}}', ['id'], '{{%elements}}', ['id'], 'CASCADE', null);

        // Subscriptions
        $this->addForeignKey(null, '{{%dispatch_subscriptions}}', ['subscriberId'], '{{%dispatch_subscribers}}', ['id'], 'CASCADE', null);
        $this->addForeignKey(null, '{{%dispatch_subscriptions}}', ['mailingListId'], '{{%dispatch_mailinglists}}', ['id'], 'CASCADE', null);

        // Send Log
        $this->addForeignKey(null, '{{%dispatch_sendlog}}', ['campaignId'], '{{%dispatch_campaigns}}', ['id'], 'CASCADE', null);
        $this->addForeignKey(null, '{{%dispatch_sendlog}}', ['subscriberId'], '{{%dispatch_subscribers}}', ['id'], 'CASCADE', null);

        // Tracking
        $this->addForeignKey(null, '{{%dispatch_tracking}}', ['campaignId'], '{{%dispatch_campaigns}}', ['id'], 'CASCADE', null);
        $this->addForeignKey(null, '{{%dispatch_tracking}}', ['subscriberId'], '{{%dispatch_subscribers}}', ['id'], 'CASCADE', null);
    }
}
