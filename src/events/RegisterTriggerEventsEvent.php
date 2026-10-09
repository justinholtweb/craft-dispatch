<?php

namespace justinholtweb\dispatch\events;

use yii\base\Event;

/**
 * Lets plugins and modules name the custom events they fire, so the sequence editor can offer
 * them: `$event->events['order.completed'] = 'Commerce order completed';`
 */
class RegisterTriggerEventsEvent extends Event
{
    /** @var array<string, string> Event key => label. */
    public array $events = [];
}
