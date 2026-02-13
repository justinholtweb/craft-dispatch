<?php

namespace justinholtweb\dispatch\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $campaignId
 * @property int $subscriberId
 * @property string $status
 * @property string|null $errorMessage
 * @property string $sentAt
 * @property string|null $messageId
 */
class SendLogRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%dispatch_sendlog}}';
    }
}
