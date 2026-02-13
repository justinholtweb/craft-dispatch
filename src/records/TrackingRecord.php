<?php

namespace justinholtweb\dispatch\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $campaignId
 * @property int $subscriberId
 * @property string $type
 * @property string|null $url
 * @property string|null $ipAddress
 * @property string|null $userAgent
 * @property string $trackedAt
 */
class TrackingRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%dispatch_tracking}}';
    }
}
