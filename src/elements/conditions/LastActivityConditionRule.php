<?php

namespace justinholtweb\dispatch\elements\conditions;

use Craft;
use craft\base\conditions\BaseDateRangeConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\DateTimeHelper;
use justinholtweb\dispatch\elements\db\SubscriberQuery;
use justinholtweb\dispatch\records\TrackingRecord;

/**
 * Engagement: when the subscriber last opened or clicked anything. “Is empty” finds people who
 * never have; “before 90 days ago” finds the ones going cold.
 */
class LastActivityConditionRule extends BaseDateRangeConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('dispatch', 'Last opened or clicked');
    }

    public function getGroupLabel(): ?string
    {
        return Craft::t('dispatch', 'Engagement');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['lastActivity'];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var SubscriberQuery $query */
        $query->lastActivity($this->queryParamValue());
    }

    public function matchElement(ElementInterface $element): bool
    {
        $last = TrackingRecord::find()->where(['subscriberId' => $element->id])->max('trackedAt');

        return $this->matchValue($last ? DateTimeHelper::toDateTime($last) ?: null : null);
    }
}
