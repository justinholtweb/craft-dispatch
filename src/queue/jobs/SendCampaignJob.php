<?php

namespace justinholtweb\dispatch\queue\jobs;

use Craft;
use craft\helpers\Db;
use craft\queue\BaseJob;
use justinholtweb\dispatch\elements\Campaign;
use justinholtweb\dispatch\elements\db\SubscriberQuery;
use justinholtweb\dispatch\elements\Subscriber;
use justinholtweb\dispatch\enums\CampaignStatus;
use justinholtweb\dispatch\events\CampaignEvent;
use justinholtweb\dispatch\Plugin;
use justinholtweb\dispatch\records\SendLogRecord;
use RuntimeException;
use yii\db\Expression;

/**
 * Sends a campaign to its audience — all of it, or one phase of an A/B subject test.
 *
 * Recipients already in the send log for this campaign are skipped, and the audience is walked by
 * ID rather than by offset, so a job that is retried after dying half way carries on where it
 * stopped instead of mailing the first half again.
 */
class SendCampaignJob extends BaseJob
{
    /** Everyone, one subject. */
    public const PHASE_ALL = 'all';
    /** A/B test: a slice of the audience, split across the subject variants. */
    public const PHASE_TEST = 'test';
    /** A/B test decided: everyone else, with the winning subject. */
    public const PHASE_REMAINDER = 'remainder';

    public int $campaignId = 0;
    public string $phase = self::PHASE_ALL;

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $campaign = Campaign::find()->id($this->campaignId)->status(null)->one();
        if (!$campaign) {
            throw new RuntimeException("Campaign {$this->campaignId} not found.");
        }

        $audience = $plugin->campaigns->audienceQuery($campaign);

        if ($audience === null) {
            $this->_markFailed($campaign, 'The campaign has no mailing list or segment to send to (segments need Dispatch Pro).');
            return;
        }

        if ($this->phase !== self::PHASE_REMAINDER) {
            // Fire before-send event
            $event = new CampaignEvent(['campaign' => $campaign]);
            $campaign->trigger(Campaign::EVENT_BEFORE_SEND, $event);

            if (!$event->isValid) {
                $this->_markFailed($campaign, 'Send cancelled by event handler.');
                return;
            }
        }

        $remaining = (clone $audience)->notSentCampaignId($campaign->id);

        if ($this->phase === self::PHASE_ALL && (int)$remaining->count() === 0 && !$this->_hasLog($campaign)) {
            $this->_markFailed($campaign, 'No active subscribers in the audience.');
            return;
        }

        if ($this->phase === self::PHASE_TEST) {
            $this->_sendTest($queue, $campaign, $remaining);
            return;
        }

        $variant = $this->phase === self::PHASE_REMAINDER ? $campaign->abWinner : null;

        if ($this->phase === self::PHASE_REMAINDER) {
            // The audience is evaluated again now: people who joined during the wait get the winner.
            Db::update('{{%dispatch_campaigns}}', [
                'totalRecipients' => (int)SendLogRecord::find()->where(['campaignId' => $campaign->id])->count() + (int)$remaining->count(),
            ], ['id' => $campaign->id], [], false);
        }

        $this->_sendAll($queue, $campaign, $remaining, $variant, false);
        $this->_markSent($campaign);
    }

    protected function defaultDescription(): ?string
    {
        return match ($this->phase) {
            self::PHASE_TEST => Craft::t('dispatch', 'Sending A/B test for campaign #{id}', ['id' => $this->campaignId]),
            self::PHASE_REMAINDER => Craft::t('dispatch', 'Sending A/B winner for campaign #{id}', ['id' => $this->campaignId]),
            default => Craft::t('dispatch', 'Sending campaign #{id}', ['id' => $this->campaignId]),
        };
    }

    /**
     * The test phase: a slice of the audience, split across the variants, then wait for results.
     */
    private function _sendTest($queue, Campaign $campaign, SubscriberQuery $remaining): void
    {
        $plugin = Plugin::getInstance();
        $alreadySent = (int)SendLogRecord::find()->where(['campaignId' => $campaign->id])->count();

        // Planned over the whole audience (sent or not), so a retried job reproduces the same
        // groups and only sends the part it had not reached.
        $audience = $plugin->campaigns->audienceQuery($campaign);
        $plan = $plugin->abTesting->testPlan($campaign, array_map('intval', $audience?->ids() ?? []));

        if ($plan === [] && $alreadySent === 0) {
            $this->_markFailed($campaign, 'No active subscribers in the audience.');
            return;
        }

        $total = array_sum(array_map('count', $plan)) ?: 1;
        $done = 0;
        $batchSize = $plugin->getSettings()->sendBatchSize;

        foreach ($plan as $variant => $ids) {
            foreach (array_chunk($ids, $batchSize) as $chunk) {
                $subscribers = (clone $remaining)->id($chunk)->all();

                if ($subscribers !== []) {
                    $this->_count($campaign, $plugin->sender->sendBatch($campaign, $subscribers, $variant, true));
                }

                $done += count($chunk);
                $this->setProgress($queue, $done / $total);
            }
        }

        $plugin->abTesting->startWaiting($campaign);
    }

    /**
     * Sends to everyone left in the query, walking it by ID.
     */
    private function _sendAll($queue, Campaign $campaign, SubscriberQuery $remaining, ?int $variant, bool $isTest): void
    {
        $plugin = Plugin::getInstance();
        $batchSize = $plugin->getSettings()->sendBatchSize;
        $total = max(1, (int)$remaining->count());
        $done = 0;
        $lastId = 0;

        while (true) {
            /** @var Subscriber[] $subscribers */
            $subscribers = (clone $remaining)
                ->andWhere(['>', 'elements.id', $lastId])
                ->orderBy(['elements.id' => SORT_ASC])
                ->limit($batchSize)
                ->all();

            if (empty($subscribers)) {
                break;
            }

            $lastId = (int)end($subscribers)->id;

            $this->_count($campaign, $plugin->sender->sendBatch($campaign, $subscribers, $variant, $isTest));

            $done += count($subscribers);
            $this->setProgress($queue, min(1, $done / $total));
        }
    }

    /** @param array{sent: int, failed: int} $results */
    private function _count(Campaign $campaign, array $results): void
    {
        Db::update('{{%dispatch_campaigns}}', [
            'totalSent' => new Expression('[[totalSent]] + ' . (int)$results['sent']),
            'totalFailed' => new Expression('[[totalFailed]] + ' . (int)$results['failed']),
        ], ['id' => $campaign->id], [], false);
    }

    private function _markSent(Campaign $campaign): void
    {
        // Counters were incremented in SQL as batches went out; read them back before saving.
        $fresh = Campaign::find()->id($campaign->id)->status(null)->one() ?? $campaign;
        $fresh->campaignStatus = CampaignStatus::Sent->value;
        $fresh->sentAt = Db::prepareDateForDb(new \DateTime());
        Craft::$app->getElements()->saveElement($fresh);

        // Fire after-send event
        $event = new CampaignEvent(['campaign' => $fresh]);
        $fresh->trigger(Campaign::EVENT_AFTER_SEND, $event);
    }

    private function _hasLog(Campaign $campaign): bool
    {
        return SendLogRecord::find()->where(['campaignId' => $campaign->id])->exists();
    }

    private function _markFailed(Campaign $campaign, string $reason): void
    {
        $campaign->campaignStatus = CampaignStatus::Failed->value;
        Craft::$app->getElements()->saveElement($campaign);
        Craft::error("Campaign {$campaign->id} failed: {$reason}", 'dispatch');
    }
}
