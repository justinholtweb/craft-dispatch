<?php

namespace justinholtweb\dispatch\elements\conditions;

use craft\elements\conditions\ElementCondition;

/**
 * The condition behind dynamic segments, and the filter on the Subscribers index.
 *
 * Craft adds the generic element rules (status, date created, ID…) and a rule for every custom
 * field in the subscriber field layout; these are Dispatch's own: lists, signup date, email and
 * engagement.
 */
class SubscriberCondition extends ElementCondition
{
    protected function selectableConditionRules(): array
    {
        return array_merge(parent::selectableConditionRules(), [
            EmailConditionRule::class,
            MailingListConditionRule::class,
            SignupDateConditionRule::class,
            OpenedCampaignConditionRule::class,
            ClickedCampaignConditionRule::class,
            LastActivityConditionRule::class,
        ]);
    }
}
