<?php

namespace justinholtweb\dispatch\elements\conditions;

use Craft;
use craft\base\conditions\BaseMultiSelectConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;
use justinholtweb\dispatch\elements\Campaign;
use justinholtweb\dispatch\elements\db\SubscriberQuery;
use justinholtweb\dispatch\records\TrackingRecord;

/**
 * Engagement: opened (or did not open) any of the chosen campaigns. “Any campaign” is an option,
 * so “has never opened anything” is one rule.
 */
class OpenedCampaignConditionRule extends BaseMultiSelectConditionRule implements ElementConditionRuleInterface
{
    public const ANY = '*';

    /** The tracking event this rule looks at. */
    protected const TYPE = 'open';

    public function getLabel(): string
    {
        return Craft::t('dispatch', 'Opened campaign');
    }

    public function getGroupLabel(): ?string
    {
        return Craft::t('dispatch', 'Engagement');
    }

    public function getExclusiveQueryParams(): array
    {
        return [];
    }

    protected function options(): array
    {
        $options = [self::ANY => Craft::t('dispatch', 'Any campaign')];

        foreach (Campaign::find()->orderBy(['elements.dateCreated' => SORT_DESC])->limit(500)->all() as $campaign) {
            $options[(string)$campaign->id] = (string)$campaign->title;
        }

        return $options;
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var SubscriberQuery $query */
        $values = $this->getValues();
        $param = in_array(self::ANY, $values, true) ? '*' : $values;
        $negate = $this->operator === self::OPERATOR_NOT_IN;

        if (static::TYPE === 'open') {
            $negate ? $query->notOpenedCampaigns($param) : $query->openedCampaigns($param);
        } else {
            $negate ? $query->notClickedCampaigns($param) : $query->clickedCampaigns($param);
        }
    }

    public function matchElement(ElementInterface $element): bool
    {
        $campaignIds = array_map('strval', TrackingRecord::find()
            ->select('campaignId')
            ->distinct()
            ->where(['subscriberId' => $element->id, 'type' => static::TYPE])
            ->column());

        if (in_array(self::ANY, $this->getValues(), true) && $campaignIds !== []) {
            $campaignIds[] = self::ANY;
        }

        return $this->matchValue($campaignIds);
    }
}
