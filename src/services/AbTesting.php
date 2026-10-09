<?php

namespace justinholtweb\dispatch\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use DateInterval;
use DateTime;
use justinholtweb\dispatch\elements\Campaign;
use justinholtweb\dispatch\events\CampaignEvent;
use justinholtweb\dispatch\Plugin;
use justinholtweb\dispatch\queue\jobs\SendCampaignJob;

/**
 * A/B subject line tests (Pro).
 *
 * A campaign with a test enabled sends in two phases:
 *
 * 1. **Test.** `abTestPercent` of the audience, picked at random (but repeatably — the order is a
 *    hash of the campaign and subscriber IDs), split evenly across the subject variants. Variant 0
 *    is the campaign's own subject; the alternatives are 1, 2… Each send records its variant.
 * 2. **Wait** `abWaitMinutes`, then pick the winner — the variant with the best unique open rate
 *    (or click rate) among its test recipients; ties go to the other metric, then the lower
 *    variant. The scheduler does this; nobody has to come back.
 * 3. **Remainder.** Everyone in the audience who has not been sent the campaign gets the winning
 *    subject. The audience is evaluated again at that point, so people who joined during the wait
 *    are included.
 *
 * Results are on the campaign screen, from the send log and tracking tables — nothing is cached.
 */
class AbTesting extends Component
{
    /** @event CampaignEvent After the winner has been picked, before the remainder is queued. */
    public const EVENT_AFTER_PICK_WINNER = 'afterPickWinner';

    /** Whether a campaign will be sent as an A/B test (Pro, enabled, at least two subjects). */
    public function isTest(Campaign $campaign): bool
    {
        return $campaign->abEnabled
            && \justinholtweb\dispatch\models\Edition::isPro()
            && count($campaign->getSubjectVariants()) >= 2;
    }

    /**
     * Splits an audience into the test groups.
     *
     * @param int[] $subscriberIds The whole audience.
     * @return array<int, int[]> Variant number => subscriber IDs. Everyone not listed is the remainder.
     */
    public function testPlan(Campaign $campaign, array $subscriberIds): array
    {
        $variants = count($campaign->getSubjectVariants());
        $total = count($subscriberIds);

        if ($total === 0 || $variants < 2) {
            return [];
        }

        // At least one recipient per variant, at most the whole audience.
        $size = min($total, max($variants, (int)ceil($total * $campaign->abTestPercent / 100)));

        // A stable shuffle: same campaign and audience, same groups — so a retried job agrees with
        // the first attempt about who is in the test.
        usort($subscriberIds, static fn($a, $b) => strcmp(
            hash('sha256', $campaign->id . ':' . $a),
            hash('sha256', $campaign->id . ':' . $b),
        ));

        $plan = array_fill(0, $variants, []);

        foreach (array_slice($subscriberIds, 0, $size) as $i => $id) {
            $plan[$i % $variants][] = (int)$id;
        }

        return $plan;
    }

    /** Test sent: wait, then pick a winner. */
    public function startWaiting(Campaign $campaign): void
    {
        $decideAt = (new DateTime())->add(new DateInterval('PT' . max(0, $campaign->abWaitMinutes) . 'M'));

        Db::update('{{%dispatch_campaigns}}', [
            'abStatus' => 'waiting',
            'abDecideAt' => Db::prepareDateForDb($decideAt),
        ], ['id' => $campaign->id], [], false);

        $campaign->abStatus = 'waiting';
        $campaign->abDecideAt = Db::prepareDateForDb($decideAt);

        Plugin::getInstance()->scheduler->arm($decideAt);
    }

    /**
     * Picks winners for every test whose wait is over, and queues their remainder sends.
     *
     * Runs whatever the edition: a test that started under Pro is finished rather than left
     * half-sent.
     */
    public function decideDue(): int
    {
        $ids = (new Query())->select('id')->from('{{%dispatch_campaigns}}')
            ->where(['abStatus' => 'waiting'])
            ->andWhere(['<=', 'abDecideAt', Scheduler::dbNow()])
            ->column();

        $decided = 0;

        foreach ($ids as $id) {
            if ($this->decide((int)$id)) {
                $decided++;
            }
        }

        return $decided;
    }

    /**
     * Picks the winner for one campaign now and queues the remainder. Claims the campaign first
     * (waiting → deciding) so two runs cannot both queue a remainder.
     */
    public function decide(int $campaignId): bool
    {
        $claimed = Db::update('{{%dispatch_campaigns}}', ['abStatus' => 'deciding'], ['id' => $campaignId, 'abStatus' => 'waiting'], [], false);

        if ($claimed !== 1) {
            return false;
        }

        $campaign = Campaign::find()->id($campaignId)->status(null)->one();

        if (!$campaign) {
            return false;
        }

        $winner = $this->pickWinner($campaign);

        Db::update('{{%dispatch_campaigns}}', ['abStatus' => 'decided', 'abWinner' => $winner], ['id' => $campaignId], [], false);
        $campaign->abStatus = 'decided';
        $campaign->abWinner = $winner;

        $this->trigger(self::EVENT_AFTER_PICK_WINNER, new CampaignEvent(['campaign' => $campaign]));

        Craft::$app->getQueue()->push(new SendCampaignJob([
            'campaignId' => $campaignId,
            'phase' => SendCampaignJob::PHASE_REMAINDER,
        ]));

        return true;
    }

    /** The winning variant by the campaign's metric, with the other metric then the lower variant as tiebreaks. */
    public function pickWinner(Campaign $campaign): int
    {
        $results = $this->results($campaign);

        usort($results, static function(array $a, array $b) use ($campaign) {
            [$first, $second] = $campaign->abMetric === 'clicks' ? ['clickRate', 'openRate'] : ['openRate', 'clickRate'];

            return [$b[$first], $b[$second], $a['variant']] <=> [$a[$first], $a[$second], $b['variant']];
        });

        return $results[0]['variant'] ?? 0;
    }

    /**
     * Per-variant results of the test phase: who got which subject, and how many of them opened
     * and clicked (unique subscribers). Remainder sends are not counted — they all got the winner.
     *
     * @return array<int, array{variant: int, subject: string, sent: int, opens: int, clicks: int, openRate: float, clickRate: float, winner: bool}>
     */
    public function results(Campaign $campaign): array
    {
        $sent = (new Query())->select(['variant', 'n' => 'COUNT(*)'])->from('{{%dispatch_sendlog}}')
            ->where(['campaignId' => $campaign->id])->andWhere(['not', ['variant' => null]])
            ->andWhere(['not', ['status' => 'failed']])
            ->groupBy(['variant'])->pairs();

        $engaged = static fn(string $type) => (new Query())
            ->select(['l.variant', 'n' => 'COUNT(DISTINCT [[t.subscriberId]])'])
            ->from(['t' => '{{%dispatch_tracking}}'])
            ->innerJoin(['l' => '{{%dispatch_sendlog}}'], '[[l.campaignId]] = [[t.campaignId]] AND [[l.subscriberId]] = [[t.subscriberId]]')
            ->where(['t.campaignId' => $campaign->id, 't.type' => $type])
            ->andWhere(['not', ['l.variant' => null]])
            ->groupBy(['l.variant'])->pairs();

        $opens = $engaged('open');
        $clicks = $engaged('click');

        $results = [];

        foreach ($campaign->getSubjectVariants() as $variant => $subject) {
            $n = (int)($sent[$variant] ?? 0);
            $o = (int)($opens[$variant] ?? 0);
            $c = (int)($clicks[$variant] ?? 0);

            $results[] = [
                'variant' => $variant,
                'subject' => $subject,
                'sent' => $n,
                'opens' => $o,
                'clicks' => $c,
                'openRate' => $n ? round($o / $n, 4) : 0.0,
                'clickRate' => $n ? round($c / $n, 4) : 0.0,
                'winner' => $campaign->abStatus === 'decided' && $campaign->abWinner === $variant,
            ];
        }

        return $results;
    }
}
