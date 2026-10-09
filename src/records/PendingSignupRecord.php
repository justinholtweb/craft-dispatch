<?php

namespace justinholtweb\dispatch\records;

use craft\db\ActiveRecord;

/**
 * A double opt-in sign-up waiting for its confirmation link to be used.
 *
 * @property int $id
 * @property string $email
 * @property string|null $firstName
 * @property string|null $lastName
 * @property array|string $listIds
 * @property string $tokenHash
 * @property string $expiresAt
 * @property array|string|null $consent
 * @property int|null $siteId
 * @property string $dateCreated
 * @property string $dateUpdated
 */
class PendingSignupRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%dispatch_pendingsignups}}';
    }
}
