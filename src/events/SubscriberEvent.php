<?php

namespace justinholtweb\dispatch\events;

use justinholtweb\dispatch\elements\MailingList;
use justinholtweb\dispatch\elements\Subscriber;
use yii\base\Event;

class SubscriberEvent extends Event
{
    public ?Subscriber $subscriber = null;
    public ?MailingList $mailingList = null;
}
