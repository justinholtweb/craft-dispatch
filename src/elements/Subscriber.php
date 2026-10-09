<?php

namespace justinholtweb\dispatch\elements;

use Craft;
use craft\base\Element;
use craft\elements\actions\Delete;
use craft\elements\actions\Restore;
use craft\elements\User;
use craft\enums\Color;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use justinholtweb\dispatch\elements\db\SubscriberQuery;
use justinholtweb\dispatch\enums\SubscriberStatus;
use justinholtweb\dispatch\records\SubscriberRecord;
use yii\base\InvalidConfigException;

class Subscriber extends Element
{
    public ?string $email = null;
    public ?string $firstName = null;
    public ?string $lastName = null;
    public string $status = 'active';
    public ?int $userId = null;
    public ?string $subscribedAt = null;
    public ?string $unsubscribedAt = null;
    public ?array $metadata = null;

    /** When this subscriber last agreed to be mailed through a sign-up form (5.3). */
    public ?string $consentedAt = null;

    /**
     * What they agreed to and how: `method` (`double-opt-in` or `single-opt-in`), `source`,
     * `text` and `textVersion`, `pageUrl`, `ipHash`, `lists`, `signedUpAt`, `confirmedAt` and,
     * when Toss manages consent, `toss`. See `Signups::confirm()`.
     *
     * @var array<string, mixed>|null
     */
    public ?array $consent = null;

    /**
     * MySQL hands a json column back as a string, and the element is built from the query row
     * as is — so these are decoded here rather than left to fail the array type.
     */
    public function __construct($config = [])
    {
        foreach (['metadata', 'consent'] as $key) {
            if (isset($config[$key]) && is_string($config[$key])) {
                $decoded = json_decode($config[$key], true);
                // Metadata saved before 5.3 was encoded twice: a JSON string holding the JSON.
                $decoded = is_string($decoded) ? json_decode($decoded, true) : $decoded;
                $config[$key] = is_array($decoded) ? $decoded : null;
            }
        }

        parent::__construct($config);
    }

    public static function displayName(): string
    {
        return Craft::t('dispatch', 'Subscriber');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('dispatch', 'Subscribers');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('dispatch', 'subscriber');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('dispatch', 'subscribers');
    }

    public static function refHandle(): ?string
    {
        return 'subscriber';
    }

    public static function hasContent(): bool
    {
        return true;
    }

    public static function hasTitles(): bool
    {
        return false;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function statuses(): array
    {
        return [
            'active' => ['label' => Craft::t('dispatch', 'Active'), 'color' => Color::Green],
            'unsubscribed' => ['label' => Craft::t('dispatch', 'Unsubscribed'), 'color' => Color::White],
            'bounced' => ['label' => Craft::t('dispatch', 'Bounced'), 'color' => Color::Orange],
            'complained' => ['label' => Craft::t('dispatch', 'Complained'), 'color' => Color::Red],
        ];
    }

    public static function find(): SubscriberQuery
    {
        return new SubscriberQuery(static::class);
    }

    /**
     * The condition behind dynamic segments and the index filters: lists, signup date, email,
     * engagement, plus a rule for every custom field in the subscriber field layout.
     */
    public static function createCondition(): \craft\elements\conditions\ElementConditionInterface
    {
        return Craft::createObject(\justinholtweb\dispatch\elements\conditions\SubscriberCondition::class, [static::class]);
    }

    public static function defineSources(?string $context = null): array
    {
        $sources = [
            [
                'key' => '*',
                'label' => Craft::t('dispatch', 'All Subscribers'),
            ],
        ];

        // By status
        foreach (SubscriberStatus::cases() as $status) {
            $sources[] = [
                'key' => 'status:' . $status->value,
                'label' => $status->label(),
                'criteria' => ['status' => $status->value],
            ];
        }

        // By mailing list
        $lists = MailingList::find()->all();
        if (!empty($lists)) {
            $sources[] = ['heading' => Craft::t('dispatch', 'Lists')];
            foreach ($lists as $list) {
                $sources[] = [
                    'key' => 'list:' . $list->id,
                    'label' => $list->title,
                    'criteria' => ['mailingListId' => $list->id],
                ];
            }
        }

        return $sources;
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'email' => Craft::t('dispatch', 'Email'),
            'fullName' => Craft::t('dispatch', 'Name'),
            'status' => Craft::t('dispatch', 'Status'),
            'subscribedAt' => Craft::t('dispatch', 'Subscribed'),
            'dateCreated' => Craft::t('dispatch', 'Date Created'),
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['email', 'fullName', 'status', 'subscribedAt'];
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['email', 'firstName', 'lastName'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            'email' => Craft::t('dispatch', 'Email'),
            'firstName' => Craft::t('dispatch', 'First Name'),
            'lastName' => Craft::t('dispatch', 'Last Name'),
            [
                'label' => Craft::t('dispatch', 'Date Subscribed'),
                'orderBy' => 'dispatch_subscribers.subscribedAt',
                'attribute' => 'subscribedAt',
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

    /** The one subscriber field layout (Settings → Subscriber Fields), as Craft's users have. */
    public function getFieldLayout(): ?\craft\models\FieldLayout
    {
        return Craft::$app->getFields()->getLayoutByType(self::class);
    }

    public function getUiLabel(): string
    {
        return $this->getFullName() ?: $this->email ?? '';
    }

    /**
     * {@see $consentedAt} as a date. The column holds UTC, like every date Craft stores; read
     * as a bare string it would be taken for the site's own time zone.
     */
    public function getConsentedAtDate(): ?\DateTime
    {
        return $this->consentedAt ? new \DateTime($this->consentedAt, new \DateTimeZone('UTC')) : null;
    }

    public function getFullName(): string
    {
        return trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));
    }

    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'fullName' => $this->getFullName(),
            default => parent::attributeHtml($attribute),
        };
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function getCpEditUrl(): ?string
    {
        return UrlHelper::cpUrl("dispatch/subscribers/{$this->id}");
    }

    public function canView(User $user): bool
    {
        return $user->can('dispatch:manageSubscribers') || $user->can('dispatch:accessPlugin');
    }

    public function canSave(User $user): bool
    {
        return $user->can('dispatch:manageSubscribers');
    }

    public function canDelete(User $user): bool
    {
        return $user->can('dispatch:manageSubscribers');
    }

    public function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['email'], 'required'];
        $rules[] = [['email'], 'email'];
        $rules[] = [['email'], 'string', 'max' => 255];
        $rules[] = [['firstName', 'lastName'], 'string', 'max' => 255];
        $rules[] = [['status'], 'in', 'range' => ['active', 'unsubscribed', 'bounced', 'complained']];

        return $rules;
    }

    public function afterSave(bool $isNew): void
    {
        if ($isNew) {
            $record = new SubscriberRecord();
        } else {
            $record = SubscriberRecord::findOne($this->id);
            if (!$record) {
                throw new InvalidConfigException("Invalid subscriber ID: {$this->id}");
            }
        }

        $record->id = $this->id;
        $record->email = $this->email;
        $record->firstName = $this->firstName;
        $record->lastName = $this->lastName;
        $record->status = $this->status;
        $record->userId = $this->userId;
        $record->subscribedAt = $this->subscribedAt ?? Db::prepareDateForDb(new \DateTime());
        $record->unsubscribedAt = $this->unsubscribedAt;
        // Assigned as an array, like `consent`: the json column encodes it. Encoding it here too
        // stored a JSON string inside the JSON, which then never decoded back into an array.
        $record->metadata = $this->metadata ?: null;
        $record->consentedAt = $this->consentedAt;
        // Assigned as an array: the json column encodes it once.
        $record->consent = $this->consent ?: null;

        $record->save(false);

        parent::afterSave($isNew);
    }

    public function afterDelete(): void
    {
        $record = SubscriberRecord::findOne($this->id);
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
    private const SANDBOX_PROPERTIES = ['id', 'uid', 'title', 'email', 'firstName', 'lastName', 'fullName', 'status', 'subscribedAt', 'dateCreated'];

    private const SANDBOX_METHODS = ['getFullName'];

    public function propertyAllowedInSandbox(string $property): bool
    {
        return in_array($property, self::SANDBOX_PROPERTIES, true) || parent::propertyAllowedInSandbox($property);
    }

    public function methodAllowedInSandbox(string $method): bool
    {
        return in_array($method, self::SANDBOX_METHODS, true) || parent::methodAllowedInSandbox($method);
    }
}
