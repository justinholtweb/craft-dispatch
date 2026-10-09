<?php

namespace justinholtweb\dispatch\events;

use craft\events\CancelableEvent;
use justinholtweb\dispatch\elements\Subscriber;
use justinholtweb\dispatch\models\Sequence;

/**
 * Fired before a subscriber is enrolled in a drip sequence. Set `$isValid` to false to skip the
 * enrolment.
 */
class EnrolEvent extends CancelableEvent
{
    public ?Sequence $sequence = null;
    public ?Subscriber $subscriber = null;
    /** `list` or the custom event key that triggered the enrolment. */
    public string $trigger = '';
}
