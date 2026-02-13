<?php

namespace justinholtweb\dispatch\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $email
 * @property string|null $firstName
 * @property string|null $lastName
 * @property string $status
 * @property int|null $userId
 * @property string $subscribedAt
 * @property string|null $unsubscribedAt
 * @property string|null $metadata
 */
class SubscriberRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%dispatch_subscribers}}';
    }
}
