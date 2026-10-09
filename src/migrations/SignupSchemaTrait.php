<?php

namespace justinholtweb\dispatch\migrations;

/**
 * The 5.3 sign-up schema, shared by Install and the upgrade migration so both build the same
 * thing: consent columns on subscribers, and the table double opt-in sign-ups wait in.
 *
 * A sign-up waiting for its confirmation is not a subscriber. Nobody has shown they own the
 * address yet, so it stays out of `dispatch_subscribers` (and every audience, count and
 * webhook) until the link is used, and is deleted when it expires.
 */
trait SignupSchemaTrait
{
    protected function createSignupSchema(): void
    {
        if (!$this->db->columnExists('{{%dispatch_subscribers}}', 'consentedAt')) {
            $this->addColumn('{{%dispatch_subscribers}}', 'consentedAt', $this->dateTime()->null()->after('unsubscribedAt'));
        }

        if (!$this->db->columnExists('{{%dispatch_subscribers}}', 'consent')) {
            $this->addColumn('{{%dispatch_subscribers}}', 'consent', $this->json()->null()->after('consentedAt'));
        }

        if ($this->db->tableExists('{{%dispatch_pendingsignups}}')) {
            return;
        }

        $this->createTable('{{%dispatch_pendingsignups}}', [
            'id' => $this->primaryKey(),
            // Lower-cased: one pending sign-up per address, whatever case it was typed in.
            'email' => $this->string(255)->notNull(),
            'firstName' => $this->string(255)->null(),
            'lastName' => $this->string(255)->null(),
            'listIds' => $this->json()->notNull(),
            // SHA-256 of the token in the email. The token itself is never stored.
            'tokenHash' => $this->char(64)->notNull(),
            'expiresAt' => $this->dateTime()->notNull(),
            'consent' => $this->json()->null(),
            'siteId' => $this->integer()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%dispatch_pendingsignups}}', ['email'], true);
        $this->createIndex(null, '{{%dispatch_pendingsignups}}', ['tokenHash'], true);
        $this->createIndex(null, '{{%dispatch_pendingsignups}}', ['expiresAt']);
        $this->addForeignKey(null, '{{%dispatch_pendingsignups}}', ['siteId'], '{{%sites}}', ['id'], 'SET NULL', null);
    }

    protected function dropSignupSchema(): void
    {
        $this->dropTableIfExists('{{%dispatch_pendingsignups}}');
    }
}
