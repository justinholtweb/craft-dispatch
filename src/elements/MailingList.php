<?php

namespace justinholtweb\dispatch\elements;

use Craft;
use craft\base\Element;
use craft\elements\actions\Delete;
use craft\elements\actions\Restore;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use justinholtweb\dispatch\elements\db\MailingListQuery;
use justinholtweb\dispatch\Plugin;
use justinholtweb\dispatch\records\MailingListRecord;
use yii\base\InvalidConfigException;

class MailingList extends Element
{
    public ?string $name = null;
    public ?string $handle = null;
    public ?string $description = null;
    public int $subscriberCount = 0;

    public static function displayName(): string
    {
        return Craft::t('dispatch', 'Mailing List');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('dispatch', 'Mailing Lists');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('dispatch', 'mailing list');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('dispatch', 'mailing lists');
    }

    public static function refHandle(): ?string
    {
        return 'mailingList';
    }

    public static function hasContent(): bool
    {
        return false;
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function hasStatuses(): bool
    {
        return false;
    }

    public static function find(): MailingListQuery
    {
        return new MailingListQuery(static::class);
    }

    public static function defineSources(string $context = null): array
    {
        return [
            [
                'key' => '*',
                'label' => Craft::t('dispatch', 'All Lists'),
            ],
        ];
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'name' => Craft::t('dispatch', 'Name'),
            'handle' => Craft::t('dispatch', 'Handle'),
            'subscriberCount' => Craft::t('dispatch', 'Subscribers'),
            'dateCreated' => Craft::t('dispatch', 'Date Created'),
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['name', 'handle', 'subscriberCount', 'dateCreated'];
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['name', 'handle'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            'title' => Craft::t('app', 'Title'),
            [
                'label' => Craft::t('dispatch', 'Subscriber Count'),
                'orderBy' => 'dispatch_mailinglists.subscriberCount',
                'attribute' => 'subscriberCount',
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

    public function getCpEditUrl(): ?string
    {
        return UrlHelper::cpUrl("dispatch/lists/{$this->id}");
    }

    public function canView(\craft\elements\User $user): bool
    {
        return $user->can('dispatch:manageLists') || $user->can('dispatch:accessPlugin');
    }

    public function canSave(\craft\elements\User $user): bool
    {
        return $user->can('dispatch:manageLists');
    }

    public function canDelete(\craft\elements\User $user): bool
    {
        return $user->can('dispatch:manageLists');
    }

    public function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['name', 'handle'], 'required'];
        $rules[] = [['name', 'handle'], 'string', 'max' => 255];
        $rules[] = [
            ['handle'],
            'match',
            'pattern' => '/^[a-zA-Z][a-zA-Z0-9_]*$/',
            'message' => Craft::t('dispatch', 'Handle must start with a letter and contain only letters, numbers, and underscores.'),
        ];

        return $rules;
    }

    public function afterSave(bool $isNew): void
    {
        if ($isNew) {
            $record = new MailingListRecord();
        } else {
            $record = MailingListRecord::findOne($this->id);
            if (!$record) {
                throw new InvalidConfigException("Invalid mailing list ID: {$this->id}");
            }
        }

        $record->id = $this->id;
        $record->name = $this->name;
        $record->handle = $this->handle;
        $record->description = $this->description;
        $record->subscriberCount = $this->subscriberCount;

        $record->save(false);

        parent::afterSave($isNew);
    }

    public function afterDelete(): void
    {
        $record = MailingListRecord::findOne($this->id);
        if ($record) {
            $record->delete();
        }

        parent::afterDelete();
    }

    public function getSubscribers(): array
    {
        return Subscriber::find()
            ->mailingListId($this->id)
            ->all();
    }

    public function refreshSubscriberCount(): void
    {
        $count = (int)Subscriber::find()
            ->mailingListId($this->id)
            ->subscriberStatus('active')
            ->count();

        $this->subscriberCount = $count;

        Craft::$app->getDb()->createCommand()
            ->update('{{%dispatch_mailinglists}}', ['subscriberCount' => $count], ['id' => $this->id])
            ->execute();
    }
}
