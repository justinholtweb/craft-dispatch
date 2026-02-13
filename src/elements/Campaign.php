<?php

namespace justinholtweb\dispatch\elements;

use Craft;
use craft\base\Element;
use craft\elements\actions\Delete;
use craft\elements\actions\Restore;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use justinholtweb\dispatch\elements\db\CampaignQuery;
use justinholtweb\dispatch\enums\CampaignStatus;
use justinholtweb\dispatch\Plugin;
use justinholtweb\dispatch\records\CampaignRecord;
use yii\base\InvalidConfigException;

class Campaign extends Element
{
    public ?string $subject = null;
    public ?string $fromName = null;
    public ?string $fromEmail = null;
    public ?string $replyToEmail = null;
    public ?string $templatePath = null;
    public ?string $body = null;
    public string $campaignStatus = 'draft';
    public ?int $mailingListId = null;
    public ?string $scheduledAt = null;
    public ?string $sentAt = null;
    public int $totalRecipients = 0;
    public int $totalSent = 0;
    public int $totalFailed = 0;

    public static function displayName(): string
    {
        return Craft::t('dispatch', 'Campaign');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('dispatch', 'Campaigns');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('dispatch', 'campaign');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('dispatch', 'campaigns');
    }

    public static function refHandle(): ?string
    {
        return 'campaign';
    }

    public static function hasContent(): bool
    {
        return true;
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function statuses(): array
    {
        return [
            'draft' => ['label' => Craft::t('dispatch', 'Draft'), 'color' => 'white'],
            'scheduled' => ['label' => Craft::t('dispatch', 'Scheduled'), 'color' => 'blue'],
            'sending' => ['label' => Craft::t('dispatch', 'Sending'), 'color' => 'orange'],
            'sent' => ['label' => Craft::t('dispatch', 'Sent'), 'color' => 'green'],
            'failed' => ['label' => Craft::t('dispatch', 'Failed'), 'color' => 'red'],
        ];
    }

    public static function find(): CampaignQuery
    {
        return new CampaignQuery(static::class);
    }

    public static function defineSources(string $context = null): array
    {
        return [
            [
                'key' => '*',
                'label' => Craft::t('dispatch', 'All Campaigns'),
            ],
            [
                'key' => 'status:draft',
                'label' => Craft::t('dispatch', 'Drafts'),
                'criteria' => ['campaignStatus' => 'draft'],
            ],
            [
                'key' => 'status:scheduled',
                'label' => Craft::t('dispatch', 'Scheduled'),
                'criteria' => ['campaignStatus' => 'scheduled'],
            ],
            [
                'key' => 'status:sent',
                'label' => Craft::t('dispatch', 'Sent'),
                'criteria' => ['campaignStatus' => 'sent'],
            ],
            [
                'key' => 'status:sending',
                'label' => Craft::t('dispatch', 'Sending'),
                'criteria' => ['campaignStatus' => 'sending'],
            ],
        ];
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'subject' => Craft::t('dispatch', 'Subject'),
            'campaignStatus' => Craft::t('dispatch', 'Status'),
            'mailingListId' => Craft::t('dispatch', 'List'),
            'totalSent' => Craft::t('dispatch', 'Sent'),
            'totalFailed' => Craft::t('dispatch', 'Failed'),
            'sentAt' => Craft::t('dispatch', 'Date Sent'),
            'dateCreated' => Craft::t('dispatch', 'Date Created'),
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['subject', 'campaignStatus', 'mailingListId', 'totalSent', 'sentAt'];
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['subject'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            'title' => Craft::t('app', 'Title'),
            'subject' => Craft::t('dispatch', 'Subject'),
            [
                'label' => Craft::t('dispatch', 'Date Sent'),
                'orderBy' => 'dispatch_campaigns.sentAt',
                'attribute' => 'sentAt',
                'defaultDir' => 'desc',
            ],
            [
                'label' => Craft::t('dispatch', 'Date Created'),
                'orderBy' => 'elements.dateCreated',
                'attribute' => 'dateCreated',
                'defaultDir' => 'desc',
            ],
        ];
    }

    protected static function defineActions(string $source = null): array
    {
        return [
            Delete::class,
            Restore::class,
        ];
    }

    public function getStatus(): ?string
    {
        return $this->campaignStatus;
    }

    public function getCpEditUrl(): ?string
    {
        return UrlHelper::cpUrl("dispatch/campaigns/{$this->id}");
    }

    protected function tableAttributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'campaignStatus' => '<span class="status ' . CampaignStatus::from($this->campaignStatus)->color() . '"></span>' . CampaignStatus::from($this->campaignStatus)->label(),
            'mailingListId' => $this->getMailingList()?->title ?? '—',
            default => parent::tableAttributeHtml($attribute),
        };
    }

    public function getMailingList(): ?MailingList
    {
        if (!$this->mailingListId) {
            return null;
        }

        return MailingList::find()->id($this->mailingListId)->one();
    }

    public function canView(\craft\elements\User $user): bool
    {
        return $user->can('dispatch:manageCampaigns') || $user->can('dispatch:accessPlugin');
    }

    public function canSave(\craft\elements\User $user): bool
    {
        return $user->can('dispatch:manageCampaigns');
    }

    public function canDelete(\craft\elements\User $user): bool
    {
        return $user->can('dispatch:manageCampaigns');
    }

    public function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['subject'], 'required'];
        $rules[] = [['subject', 'fromName', 'fromEmail', 'replyToEmail', 'templatePath'], 'string', 'max' => 255];
        $rules[] = [['fromEmail', 'replyToEmail'], 'email', 'skipOnEmpty' => true];
        $rules[] = [['campaignStatus'], 'in', 'range' => ['draft', 'scheduled', 'sending', 'sent', 'failed']];
        $rules[] = [['mailingListId', 'totalRecipients', 'totalSent', 'totalFailed'], 'integer'];

        return $rules;
    }

    public function afterSave(bool $isNew): void
    {
        if ($isNew) {
            $record = new CampaignRecord();
        } else {
            $record = CampaignRecord::findOne($this->id);
            if (!$record) {
                throw new InvalidConfigException("Invalid campaign ID: {$this->id}");
            }
        }

        $record->id = $this->id;
        $record->subject = $this->subject;
        $record->fromName = $this->fromName;
        $record->fromEmail = $this->fromEmail;
        $record->replyToEmail = $this->replyToEmail;
        $record->templatePath = $this->templatePath;
        $record->body = $this->body;
        $record->campaignStatus = $this->campaignStatus;
        $record->mailingListId = $this->mailingListId;
        $record->scheduledAt = $this->scheduledAt;
        $record->sentAt = $this->sentAt;
        $record->totalRecipients = $this->totalRecipients;
        $record->totalSent = $this->totalSent;
        $record->totalFailed = $this->totalFailed;

        $record->save(false);

        parent::afterSave($isNew);
    }

    public function afterDelete(): void
    {
        $record = CampaignRecord::findOne($this->id);
        if ($record) {
            $record->delete();
        }

        parent::afterDelete();
    }
}
