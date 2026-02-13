<?php

namespace justinholtweb\dispatch\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $subscriberId
 * @property int $mailingListId
 * @property string $subscribedAt
 */
class SubscriptionRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%dispatch_subscriptions}}';
    }
}
