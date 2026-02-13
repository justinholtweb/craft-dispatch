<?php

namespace justinholtweb\dispatch\events;

use yii\base\Event;

class TrackingEvent extends Event
{
    public int $campaignId = 0;
    public int $subscriberId = 0;
    public string $type = '';
    public ?string $url = null;
}
