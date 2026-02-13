<?php

namespace justinholtweb\dispatch\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $subject
 * @property string|null $fromName
 * @property string|null $fromEmail
 * @property string|null $replyToEmail
 * @property string|null $templatePath
 * @property string|null $body
 * @property string $campaignStatus
 * @property int|null $mailingListId
 * @property string|null $scheduledAt
 * @property string|null $sentAt
 * @property int $totalRecipients
 * @property int $totalSent
 * @property int $totalFailed
 */
class CampaignRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%dispatch_campaigns}}';
    }
}
