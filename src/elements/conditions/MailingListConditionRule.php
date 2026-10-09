<?php

namespace justinholtweb\dispatch\elements\conditions;

use Craft;
use craft\base\conditions\BaseMultiSelectConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;
use justinholtweb\dispatch\elements\db\SubscriberQuery;
use justinholtweb\dispatch\elements\MailingList;
use justinholtweb\dispatch\records\SubscriptionRecord;

/** On (or not on) any of the chosen mailing lists. Add the rule twice for “on A but not on B”. */
class MailingListConditionRule extends BaseMultiSelectConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('dispatch', 'Mailing list');
    }

    public function getGroupLabel(): ?string
    {
        return Craft::t('dispatch', 'Dispatch');
    }

    public function getExclusiveQueryParams(): array
    {
        return [];
    }

    protected function options(): array
    {
        $options = [];

        foreach (MailingList::find()->all() as $list) {
            $options[(string)$list->id] = (string)$list->title;
        }

        return $options;
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var SubscriberQuery $query */
        if ($this->operator === self::OPERATOR_NOT_IN) {
            $query->notInLists($this->getValues());
        } else {
            $query->inLists($this->getValues());
        }
    }

    public function matchElement(ElementInterface $element): bool
    {
        $listIds = array_map('strval', SubscriptionRecord::find()
            ->select('mailingListId')
            ->where(['subscriberId' => $element->id])
            ->column());

        return $this->matchValue($listIds);
    }
}
