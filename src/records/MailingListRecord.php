<?php

namespace justinholtweb\dispatch\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property string|null $description
 * @property int $subscriberCount
 */
class MailingListRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%dispatch_mailinglists}}';
    }
}
