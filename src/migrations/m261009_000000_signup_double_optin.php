<?php

namespace justinholtweb\dispatch\migrations;

use craft\db\Migration;

/**
 * Dispatch 5.3: front-end sign-up forms with double opt-in — consent on subscribers and the
 * pending sign-ups table.
 */
class m261009_000000_signup_double_optin extends Migration
{
    use SignupSchemaTrait;

    public function safeUp(): bool
    {
        $this->createSignupSchema();

        return true;
    }

    public function safeDown(): bool
    {
        echo "m261009_000000_signup_double_optin cannot be reverted.\n";

        return false;
    }
}
