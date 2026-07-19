<?php

namespace justinholtweb\dispatch\events;

use justinholtweb\dispatch\elements\Campaign;
use yii\base\Event;

class CampaignEvent extends Event
{
    public ?Campaign $campaign = null;

    /**
     * @var bool Whether the campaign send is valid and should proceed. Set to
     * `false` in a `beforeSend` handler to cancel the send.
     */
    public bool $isValid = true;
}
