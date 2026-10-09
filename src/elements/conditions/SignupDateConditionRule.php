<?php

namespace justinholtweb\dispatch\elements\conditions;

use Craft;
use craft\base\conditions\BaseDateRangeConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\DateTimeHelper;
use justinholtweb\dispatch\elements\db\SubscriberQuery;
use justinholtweb\dispatch\elements\Subscriber;

/** When the subscriber signed up (`subscribedAt`). */
class SignupDateConditionRule extends BaseDateRangeConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('dispatch', 'Signup date');
    }

    public function getGroupLabel(): ?string
    {
        return Craft::t('dispatch', 'Dispatch');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['subscribedAt'];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var SubscriberQuery $query */
        $query->subscribedAt($this->queryParamValue());
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Subscriber $element */
        // Stored in UTC with no zone; toDateTime() reads a zone-less string as UTC.
        return $this->matchValue($element->subscribedAt ? DateTimeHelper::toDateTime($element->subscribedAt) ?: null : null);
    }
}
