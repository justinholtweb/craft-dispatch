<?php

namespace justinholtweb\dispatch\elements;

use Craft;
use craft\base\Element;
use craft\elements\actions\Delete;
use craft\elements\actions\Restore;
use craft\elements\User;
use craft\enums\Color;
use craft\helpers\UrlHelper;
use justinholtweb\dispatch\elements\db\CampaignQuery;
use justinholtweb\dispatch\enums\CampaignStatus;
use justinholtweb\dispatch\records\CampaignRecord;
use yii\base\InvalidConfigException;

class Campaign extends Element
{
    /**
     * @event CampaignEvent The event fired before a campaign is sent. Set
     * `$event->isValid` to `false` to cancel the send.
     */
    public const EVENT_BEFORE_SEND = 'beforeSend';

    /**
     * @event CampaignEvent The event fired after a campaign has been sent.
     */
    public const EVENT_AFTER_SEND = 'afterSend';

    public ?string $subject = null;
    public ?string $fromName = null;
    public ?string $fromEmail = null;
    public ?string $replyToEmail = null;
    public ?string $templatePath = null;
    public ?string $body = null;
    public string $campaignStatus = 'draft';
    public ?int $mailingListId = null;

    /** A dynamic segment (Pro) narrowing — or, with no list, defining — the audience. */
    public ?int $segmentId = null;
    public ?string $scheduledAt = null;
    public ?string $sentAt = null;
    public int $totalRecipients = 0;
    public int $totalSent = 0;
    public int $totalFailed = 0;

    /** A/B subject test (Pro). Variant 0 is `subject`; these are variants 1, 2… */
    public bool $abEnabled = false;
    /**
     * @var string[] Stored as JSON; reached through get/setAbSubjects() so a row's JSON string
     * from the element query goes through the setter instead of failing the typed property.
     */
    private array $_abSubjects = [];
    /** Share of the audience, in percent, that receives the test. */
    public int $abTestPercent = 20;
    /** `opens` or `clicks` — how the winner is picked. */
    public string $abMetric = 'opens';
    /** How long after the test send the winner is picked and the remainder sent. */
    public int $abWaitMinutes = 240;
    /** null, `testing`, `waiting`, `deciding` or `decided`. */
    public ?string $abStatus = null;
    public ?int $abWinner = null;
    public ?string $abDecideAt = null;

    public function setAbSubjects(mixed $value): void
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        $this->_abSubjects = is_array($value) ? array_values(array_map('strval', $value)) : [];
    }

    /** @return string[] */
    public function getAbSubjects(): array
    {
        return $this->_abSubjects;
    }

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
            'draft' => ['label' => Craft::t('dispatch', 'Draft'), 'color' => Color::White],
            'scheduled' => ['label' => Craft::t('dispatch', 'Scheduled'), 'color' => Color::Blue],
            'sending' => ['label' => Craft::t('dispatch', 'Sending'), 'color' => Color::Orange],
            'sent' => ['label' => Craft::t('dispatch', 'Sent'), 'color' => Color::Green],
            'failed' => ['label' => Craft::t('dispatch', 'Failed'), 'color' => Color::Red],
        ];
    }

    public static function find(): CampaignQuery
    {
        return new CampaignQuery(static::class);
    }

    public static function defineSources(?string $context = null): array
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

    protected static function defineActions(?string $source = null): array
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

    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'campaignStatus' => '<span class="status ' . CampaignStatus::from($this->campaignStatus)->color() . '"></span>' . CampaignStatus::from($this->campaignStatus)->label(),
            'mailingListId' => $this->getMailingList()->title ?? '—',
            default => parent::attributeHtml($attribute),
        };
    }

    public function getMailingList(): ?MailingList
    {
        if (!$this->mailingListId) {
            return null;
        }

        return MailingList::find()->id($this->mailingListId)->one();
    }

    public function canView(User $user): bool
    {
        return $user->can('dispatch:manageCampaigns') || $user->can('dispatch:accessPlugin');
    }

    public function canSave(User $user): bool
    {
        return $user->can('dispatch:manageCampaigns');
    }

    public function canDelete(User $user): bool
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
        $rules[] = [['mailingListId', 'segmentId', 'totalRecipients', 'totalSent', 'totalFailed'], 'integer'];
        $rules[] = [['abTestPercent'], 'integer', 'min' => 1, 'max' => 100];
        $rules[] = [['abWaitMinutes'], 'integer', 'min' => 0, 'max' => 60 * 24 * 14];
        $rules[] = [['abMetric'], 'in', 'range' => ['opens', 'clicks']];
        $rules[] = [['abSubjects'], 'validateAbSubjects', 'skipOnEmpty' => false];

        return $rules;
    }

    /** A/B subject variants must be non-empty strings that fit the subject column. */
    public function validateAbSubjects(string $attribute): void
    {
        $this->_abSubjects = array_values(array_filter(
            array_map(static fn($s) => trim((string)$s), $this->_abSubjects),
            static fn(string $s) => $s !== '',
        ));

        foreach ($this->_abSubjects as $subject) {
            if (mb_strlen($subject) > 255) {
                $this->addError($attribute, Craft::t('dispatch', 'Subject variants may be at most 255 characters.'));
            }
        }

        if ($this->abEnabled && $this->_abSubjects === []) {
            $this->addError($attribute, Craft::t('dispatch', 'An A/B test needs at least one alternative subject line.'));
        }
    }

    /**
     * The subject line for an A/B variant: 0 is the campaign's own subject, 1… the alternatives.
     * Out-of-range variants fall back to the campaign subject.
     */
    public function getSubjectForVariant(?int $variant): string
    {
        if ($variant !== null && $variant > 0 && isset($this->_abSubjects[$variant - 1])) {
            return $this->_abSubjects[$variant - 1];
        }

        return (string)$this->subject;
    }

    /** @return string[] Every subject variant, indexed by variant number. */
    public function getSubjectVariants(): array
    {
        return array_merge([(string)$this->subject], $this->_abSubjects);
    }

    public function getSegment(): ?\justinholtweb\dispatch\models\Segment
    {
        return $this->segmentId ? \justinholtweb\dispatch\Plugin::getInstance()->segments->getById($this->segmentId) : null;
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
        $record->segmentId = $this->segmentId;
        $record->abEnabled = $this->abEnabled;
        $record->abSubjects = $this->_abSubjects ? json_encode($this->_abSubjects) : null;
        $record->abTestPercent = $this->abTestPercent;
        $record->abMetric = $this->abMetric;
        $record->abWaitMinutes = $this->abWaitMinutes;
        $record->abStatus = $this->abStatus;
        $record->abWinner = $this->abWinner;
        $record->abDecideAt = $this->abDecideAt;
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

    /**
     * What campaign Twig — rendered in Craft's sandbox — may read from this element, besides its
     * custom fields. Craft allows only custom fields by default, which would break
     * `{{ subscriber.firstName }}` in every campaign; this list is the newsletter-safe rest.
     */
    private const SANDBOX_PROPERTIES = ['id', 'uid', 'title', 'subject', 'fromName', 'fromEmail', 'replyToEmail', 'sentAt', 'scheduledAt', 'mailingList', 'dateCreated'];

    private const SANDBOX_METHODS = ['getMailingList'];

    public function propertyAllowedInSandbox(string $property): bool
    {
        return in_array($property, self::SANDBOX_PROPERTIES, true) || parent::propertyAllowedInSandbox($property);
    }

    public function methodAllowedInSandbox(string $method): bool
    {
        return in_array($method, self::SANDBOX_METHODS, true) || parent::methodAllowedInSandbox($method);
    }
}
