<?php

namespace justinholtweb\dispatch\elements\conditions;

use Craft;

/** Engagement: clicked (or did not click) a link in any of the chosen campaigns. */
class ClickedCampaignConditionRule extends OpenedCampaignConditionRule
{
    protected const TYPE = 'click';

    public function getLabel(): string
    {
        return Craft::t('dispatch', 'Clicked campaign');
    }
}
