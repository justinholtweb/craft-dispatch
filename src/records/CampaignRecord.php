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
 * @property int|null $segmentId
 * @property bool $abEnabled
 * @property string|null $abSubjects
 * @property int $abTestPercent
 * @property string $abMetric
 * @property int $abWaitMinutes
 * @property string|null $abStatus
 * @property int|null $abWinner
 * @property string|null $abDecideAt
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
