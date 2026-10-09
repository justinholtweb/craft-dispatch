<?php

namespace justinholtweb\dispatch\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\dispatch\Plugin;

/**
 * Delivers one outgoing webhook. Never throws: a failed attempt is recorded on the delivery and
 * retried by a fresh, delayed job (see `Integrations::deliver()`), so the queue's own retry can't
 * POST the same body twice.
 */
class DeliverWebhookJob extends BaseJob
{
    public int $deliveryId = 0;

    public function execute($queue): void
    {
        Plugin::getInstance()->integrations->deliver($this->deliveryId);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('dispatch', 'Delivering Dispatch webhook #{id}', ['id' => $this->deliveryId]);
    }
}
