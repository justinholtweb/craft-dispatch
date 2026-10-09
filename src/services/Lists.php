<?php

namespace justinholtweb\dispatch\services;

use Craft;
use craft\base\Component;
use justinholtweb\dispatch\elements\MailingList;
use justinholtweb\dispatch\elements\Subscriber;
use justinholtweb\dispatch\models\Edition;
use justinholtweb\dispatch\Plugin;

class Lists extends Component
{
    public function getById(int $id): ?MailingList
    {
        return MailingList::find()->id($id)->one();
    }

    public function getByHandle(string $handle): ?MailingList
    {
        return MailingList::find()->handle($handle)->one();
    }

    public function getAll(): array
    {
        return MailingList::find()->all();
    }

    public function create(MailingList $list): bool
    {
        // Check edition limit
        $currentCount = MailingList::find()->count();
        if ($currentCount >= Edition::maxMailingLists()) {
            $list->addError('handle', 'You have reached the maximum number of mailing lists for your edition.');
            return false;
        }

        // Set title from name if not set
        if (!$list->title) {
            $list->title = $list->name;
        }

        return Craft::$app->getElements()->saveElement($list);
    }

    public function update(MailingList $list): bool
    {
        return Craft::$app->getElements()->saveElement($list);
    }

    public function delete(MailingList $list): bool
    {
        return Craft::$app->getElements()->deleteElement($list);
    }

    public function addSubscriber(int $mailingListId, int $subscriberId): bool
    {
        // One code path for joining a list, so its event (sequences, webhooks) always fires.
        return Plugin::getInstance()->subscribers->subscribe($subscriberId, $mailingListId);
    }

    public function removeSubscriber(int $mailingListId, int $subscriberId): bool
    {
        return Plugin::getInstance()->subscribers->removeFromList($subscriberId, $mailingListId);
    }

    public function getSubscribers(int $mailingListId): array
    {
        return Subscriber::find()
            ->mailingListId($mailingListId)
            ->all();
    }

    public function getSubscriberIds(int $mailingListId): array
    {
        return Subscriber::find()
            ->mailingListId($mailingListId)
            ->ids();
    }
}
