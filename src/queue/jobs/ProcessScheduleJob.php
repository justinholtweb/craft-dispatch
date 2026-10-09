<?php

namespace justinholtweb\dispatch\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\dispatch\Plugin;

/**
 * Runs whatever Dispatch has falling due — scheduled campaigns, A/B winners, drip steps — then
 * queues itself again for the next thing due. Pushed with a delay by `Scheduler::arm()`; extra
 * copies are harmless, every item is claimed atomically before it is sent.
 */
class ProcessScheduleJob extends BaseJob
{
    public function execute($queue): void
    {
        Plugin::getInstance()->scheduler->run();
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('dispatch', 'Sending scheduled Dispatch email');
    }
}
