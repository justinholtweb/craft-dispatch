<?php

namespace justinholtweb\dispatch\events;

use craft\events\CancelableEvent;

/**
 * Fired before an outgoing webhook event is queued for its endpoints. Handlers may change
 * `$payload`, or set `$isValid` to false to send nothing.
 */
class IntegrationEvent extends CancelableEvent
{
    /** e.g. `subscriber.subscribed`. (Not `name`: that is Yii's own event name.) */
    public string $webhookEvent = '';
    /** @var array<string, mixed> The payload's `data`. */
    public array $payload = [];
}
