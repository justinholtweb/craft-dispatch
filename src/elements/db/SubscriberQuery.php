<?php

namespace justinholtweb\dispatch\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;

class SubscriberQuery extends ElementQuery
{
    public ?string $email = null;
    public ?string $firstName = null;
    public ?string $lastName = null;
    public ?string $subscriberStatus = null;
    public ?int $userId = null;
    public ?int $mailingListId = null;

    public function email(?string $value): self
    {
        $this->email = $value;
        return $this;
    }

    public function firstName(?string $value): self
    {
        $this->firstName = $value;
        return $this;
    }

    public function lastName(?string $value): self
    {
        $this->lastName = $value;
        return $this;
    }

    public function subscriberStatus(?string $value): self
    {
        $this->subscriberStatus = $value;
        return $this;
    }

    public function userId(?int $value): self
    {
        $this->userId = $value;
        return $this;
    }

    public function mailingListId(?int $value): self
    {
        $this->mailingListId = $value;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        $this->joinElementTable('dispatch_subscribers');

        $this->query->select([
            'dispatch_subscribers.email',
            'dispatch_subscribers.firstName',
            'dispatch_subscribers.lastName',
            'dispatch_subscribers.status',
            'dispatch_subscribers.userId',
            'dispatch_subscribers.subscribedAt',
            'dispatch_subscribers.unsubscribedAt',
            'dispatch_subscribers.metadata',
        ]);

        if ($this->email !== null) {
            $this->subQuery->andWhere(Db::parseParam('dispatch_subscribers.email', $this->email));
        }

        if ($this->firstName !== null) {
            $this->subQuery->andWhere(Db::parseParam('dispatch_subscribers.firstName', $this->firstName));
        }

        if ($this->lastName !== null) {
            $this->subQuery->andWhere(Db::parseParam('dispatch_subscribers.lastName', $this->lastName));
        }

        if ($this->subscriberStatus !== null) {
            $this->subQuery->andWhere(Db::parseParam('dispatch_subscribers.status', $this->subscriberStatus));
        }

        if ($this->userId !== null) {
            $this->subQuery->andWhere(Db::parseParam('dispatch_subscribers.userId', $this->userId));
        }

        if ($this->mailingListId !== null) {
            $this->subQuery->innerJoin(
                '{{%dispatch_subscriptions}} dispatch_subscriptions',
                '[[dispatch_subscriptions.subscriberId]] = [[dispatch_subscribers.id]]'
            );
            $this->subQuery->andWhere(Db::parseParam('dispatch_subscriptions.mailingListId', $this->mailingListId));
        }

        return parent::beforePrepare();
    }

    protected function statusCondition(string $status): mixed
    {
        return match ($status) {
            'active' => ['dispatch_subscribers.status' => 'active'],
            'unsubscribed' => ['dispatch_subscribers.status' => 'unsubscribed'],
            'bounced' => ['dispatch_subscribers.status' => 'bounced'],
            'complained' => ['dispatch_subscribers.status' => 'complained'],
            default => parent::statusCondition($status),
        };
    }
}
