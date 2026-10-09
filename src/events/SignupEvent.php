<?php

namespace justinholtweb\dispatch\events;

use justinholtweb\dispatch\elements\Subscriber;
use yii\base\Event;

/**
 * A front-end sign-up.
 *
 * `Signups::EVENT_BEFORE_SIGNUP` — set `$isValid` to false to drop it (a spam check, say). The
 * visitor is answered exactly as if it had gone through, so a refusal tells them nothing.
 * `Signups::EVENT_AFTER_CONFIRM` — `$subscriber` is the confirmed subscriber.
 */
class SignupEvent extends Event
{
    public string $email = '';

    /** @var int[] */
    public array $listIds = [];

    /**
     * `firstName`, `lastName`, `source`, `consentText`, `pageUrl`.
     *
     * @var array<string, mixed>
     */
    public array $details = [];

    public ?Subscriber $subscriber = null;

    public bool $isValid = true;
}
