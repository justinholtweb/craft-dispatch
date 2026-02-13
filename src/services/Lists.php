<?php

namespace justinholtweb\dispatch\services;

use Craft;
use craft\base\Component;
use justinholtweb\dispatch\elements\MailingList;
use justinholtweb\dispatch\elements\Subscriber;
use justinholtweb\dispatch\models\Edition;
use justinholtweb\dispatch\records\SubscriptionRecord;

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
        $existing = SubscriptionRecord::find()
            ->where(['subscriberId' => $subscriberId, 'mailingListId' => $mailingListId])
            ->one();

        if ($existing) {
            return true;
        }

        $record = new SubscriptionRecord();
        $record->subscriberId = $subscriberId;
        $record->mailingListId = $mailingListId;
        $record->subscribedAt = new \DateTime();

        $result = $record->save();

        if ($result) {
            $list = $this->getById($mailingListId);
            if ($list) {
                $list->refreshSubscriberCount();
            }
        }

        return $result;
    }

    public function removeSubscriber(int $mailingListId, int $subscriberId): bool
    {
        $result = SubscriptionRecord::deleteAll([
            'subscriberId' => $subscriberId,
            'mailingListId' => $mailingListId,
        ]);

        if ($result) {
            $list = $this->getById($mailingListId);
            if ($list) {
                $list->refreshSubscriberCount();
            }
        }

        return $result > 0;
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
