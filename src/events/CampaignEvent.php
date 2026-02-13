<?php

namespace justinholtweb\dispatch\events;

use justinholtweb\dispatch\elements\Campaign;
use yii\base\Event;

class CampaignEvent extends Event
{
    public ?Campaign $campaign = null;
}
