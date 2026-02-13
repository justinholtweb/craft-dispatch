<?php

namespace justinholtweb\dispatch\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\dispatch\models\Edition;
use justinholtweb\dispatch\Plugin;

class SyncUsersJob extends BaseJob
{
    public array $userGroupIds = [];

    public function execute($queue): void
    {
        Edition::requiresLite('User sync');

        $results = Plugin::getInstance()->subscribers->syncCraftUsers($this->userGroupIds);

        Craft::info("User sync complete: {$results['synced']} processed, {$results['created']} created, {$results['updated']} updated", 'dispatch');
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('dispatch', 'Syncing Craft users to subscribers');
    }
}
