<?php

namespace justinholtweb\dispatch\elements;

use Craft;
use craft\base\Element;
use craft\elements\actions\Delete;
use craft\elements\actions\Restore;
use craft\elements\User;
use craft\elements\conditions\ElementConditionInterface;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use justinholtweb\dispatch\elements\db\SubscriberQuery;
use justinholtweb\dispatch\enums\SubscriberStatus;
use justinholtweb\dispatch\Plugin;
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
            'active' => ['label' => Craft::t('dispatch', 'Active'), 'color' => 'green'],
            'unsubscribed' => ['label' => Craft::t('dispatch', 'Unsubscribed'), 'color' => 'white'],
            'bounced' => ['label' => Craft::t('dispatch', 'Bounced'), 'color' => 'orange'],
            'complained' => ['label' => Craft::t('dispatch', 'Complained'), 'color' => 'red'],
        ];
    }

    public static function find(): SubscriberQuery
    {
        return new SubscriberQuery(static::class);
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

    public function getUiLabel(): string
    {
        return $this->getFullName() ?: $this->email ?? '';
    }

    public function getFullName(): string
    {
        return trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));
    }

    protected function tableAttributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'fullName' => $this->getFullName(),
            default => parent::tableAttributeHtml($attribute),
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
        $record->metadata = $this->metadata ? json_encode($this->metadata) : null;

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
}
