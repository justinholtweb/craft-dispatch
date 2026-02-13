<?php

namespace justinholtweb\dispatch\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\dispatch\elements\Campaign;
use justinholtweb\dispatch\elements\Subscriber;
use justinholtweb\dispatch\enums\CampaignStatus;
use justinholtweb\dispatch\events\CampaignEvent;
use justinholtweb\dispatch\Plugin;

class SendCampaignJob extends BaseJob
{
    public int $campaignId = 0;

    public function execute($queue): void
    {
        $campaign = Plugin::getInstance()->campaigns->getById($this->campaignId);
        if (!$campaign) {
            throw new \RuntimeException("Campaign {$this->campaignId} not found.");
        }

        if (!$campaign->mailingListId) {
            $this->markFailed($campaign, 'No mailing list assigned.');
            return;
        }

        // Fire before-send event
        $event = new CampaignEvent(['campaign' => $campaign]);
        Campaign::trigger(Campaign::class, Campaign::EVENT_BEFORE_SEND ?? 'beforeSend', $event);

        if (!$event->isValid) {
            $this->markFailed($campaign, 'Send cancelled by event handler.');
            return;
        }

        $settings = Plugin::getInstance()->getSettings();
        $batchSize = $settings->sendBatchSize;

        // Get all active subscribers in the mailing list
        $subscriberQuery = Subscriber::find()
            ->mailingListId($campaign->mailingListId)
            ->subscriberStatus('active');

        $totalCount = (int)$subscriberQuery->count();

        if ($totalCount === 0) {
            $this->markFailed($campaign, 'No active subscribers in the mailing list.');
            return;
        }

        $campaign->totalRecipients = $totalCount;
        Craft::$app->getElements()->saveElement($campaign);

        $offset = 0;
        $totalSent = 0;
        $totalFailed = 0;

        while ($offset < $totalCount) {
            $subscribers = (clone $subscriberQuery)
                ->offset($offset)
                ->limit($batchSize)
                ->all();

            if (empty($subscribers)) {
                break;
            }

            $results = Plugin::getInstance()->sender->sendBatch($campaign, $subscribers);
            $totalSent += $results['sent'];
            $totalFailed += $results['failed'];

            // Update campaign counters
            $campaign->totalSent = $totalSent;
            $campaign->totalFailed = $totalFailed;
            Craft::$app->getDb()->createCommand()
                ->update('{{%dispatch_campaigns}}', [
                    'totalSent' => $totalSent,
                    'totalFailed' => $totalFailed,
                ], ['id' => $campaign->id])
                ->execute();

            $offset += $batchSize;
            $this->setProgress($queue, $offset / $totalCount);
        }

        // Mark campaign as sent
        $campaign->campaignStatus = CampaignStatus::Sent->value;
        $campaign->sentAt = (new \DateTime())->format('Y-m-d H:i:s');
        $campaign->totalSent = $totalSent;
        $campaign->totalFailed = $totalFailed;
        Craft::$app->getElements()->saveElement($campaign);

        // Fire after-send event
        $event = new CampaignEvent(['campaign' => $campaign]);
        Campaign::trigger(Campaign::class, Campaign::EVENT_AFTER_SEND ?? 'afterSend', $event);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('dispatch', 'Sending campaign #{id}', ['id' => $this->campaignId]);
    }

    private function markFailed(Campaign $campaign, string $reason): void
    {
        $campaign->campaignStatus = CampaignStatus::Failed->value;
        Craft::$app->getElements()->saveElement($campaign);
        Craft::error("Campaign {$campaign->id} failed: {$reason}", 'dispatch');
    }
}
