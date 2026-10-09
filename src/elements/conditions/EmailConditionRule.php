<?php

namespace justinholtweb\dispatch\elements\conditions;

use Craft;
use craft\base\conditions\BaseTextConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;
use justinholtweb\dispatch\elements\db\SubscriberQuery;
use justinholtweb\dispatch\elements\Subscriber;

/** Email address — typically “ends with @example.com”. */
class EmailConditionRule extends BaseTextConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('dispatch', 'Email');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['email'];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var SubscriberQuery $query */
        $query->email($this->paramValue());
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Subscriber $element */
        return $this->matchValue((string)$element->email);
    }
}
