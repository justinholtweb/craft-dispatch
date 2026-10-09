<?php

namespace justinholtweb\dispatch\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use justinholtweb\dispatch\elements\Subscriber;

/**
 * @method Subscriber[] all($db = null)
 * @method Subscriber|null one($db = null)
 * @method Subscriber|null nth(int $n, ?\yii\db\Connection $db = null)
 */
class SubscriberQuery extends ElementQuery
{
    public ?string $email = null;
    public ?string $firstName = null;
    public ?string $lastName = null;
    public ?string $subscriberStatus = null;
    public ?int $userId = null;
    public ?int $mailingListId = null;

    /**
     * Segment params (5.2). Typed `mixed`: Craft::configure() assigns source and condition
     * criteria straight to these properties, bypassing the setters.
     */
    /** @var mixed Subscribed to any of these list IDs. */
    public mixed $inLists = null;
    /** @var mixed Subscribed to none of these list IDs. */
    public mixed $notInLists = null;
    /** @var mixed Opened any of these campaign IDs (`*` = any campaign). */
    public mixed $openedCampaigns = null;
    /** @var mixed Opened none of these campaign IDs (`*` = never opened anything). */
    public mixed $notOpenedCampaigns = null;
    /** @var mixed Clicked a link in any of these campaign IDs (`*` = any campaign). */
    public mixed $clickedCampaigns = null;
    /** @var mixed Clicked nothing in these campaign IDs (`*` = never clicked anything). */
    public mixed $notClickedCampaigns = null;
    /** @var mixed Date param on the subscriber's most recent open or click. */
    public mixed $lastActivity = null;
    /** @var mixed Date param on `subscribedAt`. */
    public mixed $subscribedAt = null;
    /** @var int|null Only subscribers this campaign has not been sent to (no send-log row). */
    public ?int $notSentCampaignId = null;

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

    public function inLists(mixed $value): self
    {
        $this->inLists = $value;
        return $this;
    }

    public function notInLists(mixed $value): self
    {
        $this->notInLists = $value;
        return $this;
    }

    public function openedCampaigns(mixed $value): self
    {
        $this->openedCampaigns = $value;
        return $this;
    }

    public function notOpenedCampaigns(mixed $value): self
    {
        $this->notOpenedCampaigns = $value;
        return $this;
    }

    public function clickedCampaigns(mixed $value): self
    {
        $this->clickedCampaigns = $value;
        return $this;
    }

    public function notClickedCampaigns(mixed $value): self
    {
        $this->notClickedCampaigns = $value;
        return $this;
    }

    public function lastActivity(mixed $value): self
    {
        $this->lastActivity = $value;
        return $this;
    }

    public function notSentCampaignId(?int $value): self
    {
        $this->notSentCampaignId = $value;
        return $this;
    }

    public function subscribedAt(mixed $value): self
    {
        $this->subscribedAt = $value;
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

        if ($this->inLists !== null) {
            $this->subQuery->andWhere(['exists', $this->_listSubquery($this->inLists)]);
        }

        if ($this->notInLists !== null) {
            $this->subQuery->andWhere(['not exists', $this->_listSubquery($this->notInLists)]);
        }

        foreach ([
            ['openedCampaigns', 'open', 'exists'],
            ['notOpenedCampaigns', 'open', 'not exists'],
            ['clickedCampaigns', 'click', 'exists'],
            ['notClickedCampaigns', 'click', 'not exists'],
        ] as [$param, $type, $operator]) {
            if ($this->$param !== null) {
                $this->subQuery->andWhere([$operator, $this->_trackingSubquery($type, $this->$param)]);
            }
        }

        if ($this->lastActivity !== null) {
            $this->subQuery->andWhere(Db::parseDateParam(
                '(SELECT MAX([[dt_activity.trackedAt]]) FROM {{%dispatch_tracking}} [[dt_activity]] WHERE [[dt_activity.subscriberId]] = [[dispatch_subscribers.id]])',
                $this->lastActivity,
            ));
        }

        if ($this->notSentCampaignId !== null) {
            $this->subQuery->andWhere(['not exists', (new \craft\db\Query())
                ->from(['dt_log' => '{{%dispatch_sendlog}}'])
                ->where('[[dt_log.subscriberId]] = [[dispatch_subscribers.id]]')
                ->andWhere(['dt_log.campaignId' => $this->notSentCampaignId]), ]);
        }

        if ($this->subscribedAt !== null) {
            $this->subQuery->andWhere(Db::parseDateParam('dispatch_subscribers.subscribedAt', $this->subscribedAt));
        }

        return parent::beforePrepare();
    }

    private function _listSubquery(mixed $listIds): \craft\db\Query
    {
        return (new \craft\db\Query())
            ->from(['dt_lists' => '{{%dispatch_subscriptions}}'])
            ->where('[[dt_lists.subscriberId]] = [[dispatch_subscribers.id]]')
            ->andWhere(['dt_lists.mailingListId' => $this->_ids($listIds)]);
    }

    private function _trackingSubquery(string $type, mixed $campaignIds): \craft\db\Query
    {
        $query = (new \craft\db\Query())
            ->from(['dt_track' => '{{%dispatch_tracking}}'])
            ->where('[[dt_track.subscriberId]] = [[dispatch_subscribers.id]]')
            ->andWhere(['dt_track.type' => $type]);

        if ($campaignIds !== '*') {
            $query->andWhere(['dt_track.campaignId' => $this->_ids($campaignIds)]);
        }

        return $query;
    }

    /**
     * IDs from a param value, cast to ints. An empty list matches nothing (rather than everything).
     *
     * @return int[]
     */
    private function _ids(mixed $value): array
    {
        $ids = array_values(array_filter(array_map('intval', (array)$value)));

        return $ids ?: [0];
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
