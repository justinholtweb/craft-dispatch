<?php

namespace justinholtweb\dispatch\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTime;
use justinholtweb\dispatch\elements\Campaign;
use justinholtweb\dispatch\models\Edition;
use justinholtweb\dispatch\Plugin;
use justinholtweb\dispatch\queue\jobs\ProcessScheduleJob;

/**
 * Dispatch's clock: scheduled campaigns, A/B test winners and drip steps all fall due at a time,
 * and this is what notices.
 *
 * It needs no cron. Whenever something is given a due time, `arm()` pushes a `ProcessScheduleJob`
 * onto Craft's queue with a delay that lands on it; the job runs everything due and arms itself
 * for the next thing. `craft dispatch/scheduler/run` does the same from cron, for sites whose
 * queue only runs on web requests.
 *
 * Nothing here relies on running exactly once: every item is claimed with a conditional UPDATE
 * before it is sent, so overlapping runs (a cron tick during a queue job) cannot send twice.
 */
class Scheduler extends Component
{
    /** Cache key holding the time (unix) the earliest pending scheduler job will run. */
    public const CACHE_KEY = 'dispatch:scheduler:armedFor';

    /** Drip emails sent per run; more due than this re-arms immediately. */
    public int $batchLimit = 200;

    /**
     * Runs everything due now.
     *
     * @return array{campaigns: int, abTests: int, sequenceSends: int}
     */
    public function run(): array
    {
        // Forget the armed job first: anything given a due time while this run is in progress
        // arms a new job instead of assuming this one will see it.
        Craft::$app->getCache()->delete(self::CACHE_KEY);

        $plugin = Plugin::getInstance();

        $results = [
            'campaigns' => $this->sendDueCampaigns(),
            'abTests' => $plugin->abTesting->decideDue(),
            'sequenceSends' => $plugin->sequences->processDue($this->batchLimit),
        ];

        $this->rearm();

        return $results;
    }

    /**
     * Starts every campaign whose scheduled time has passed. `Campaigns::send()` claims the
     * campaign (scheduled → sending) atomically, so a campaign picked up by two runs starts once.
     */
    public function sendDueCampaigns(): int
    {
        $ids = Campaign::find()
            ->status(null)
            ->campaignStatus('scheduled')
            ->scheduledAt('<= ' . DateTimeHelper::toIso8601(DateTimeHelper::now()))
            ->ids();

        $started = 0;

        foreach ($ids as $id) {
            if (Plugin::getInstance()->campaigns->send((int)$id)) {
                $started++;
            }
        }

        return $started;
    }

    /** The earliest time anything Dispatch is waiting on falls due, or null if nothing is. */
    public function nextDueAt(): ?DateTime
    {
        $candidates = [
            (new Query())->from('{{%dispatch_campaigns}}')->where(['campaignStatus' => 'scheduled'])->min('[[scheduledAt]]'),
            (new Query())->from('{{%dispatch_campaigns}}')->where(['abStatus' => 'waiting'])->min('[[abDecideAt]]'),
        ];

        // Paused (disabled) sequences, and every sequence on a site that is no longer Pro, are
        // left out — counting them would re-arm for a time in the past, over and over.
        if (Edition::isPro()) {
            $candidates[] = (new Query())
                ->from(['s' => '{{%dispatch_sequencesends}}'])
                ->innerJoin(['e' => '{{%dispatch_enrolments}}'], '[[e.id]] = [[s.enrolmentId]]')
                ->innerJoin(['q' => '{{%dispatch_sequences}}'], '[[q.id]] = [[e.sequenceId]]')
                ->where(['s.status' => 'pending', 'q.enabled' => true])
                ->min('[[s.dueAt]]');
        }

        $times = array_filter(array_map(
            // Stored in UTC without a zone; toDateTime() reads such a string as UTC.
            static fn($v) => $v ? DateTimeHelper::toDateTime($v) ?: null : null,
            $candidates,
        ));

        if ($times === []) {
            return null;
        }

        usort($times, static fn(DateTime $a, DateTime $b) => $a <=> $b);

        return $times[0];
    }

    /** Arms a job for whatever is due next. */
    public function rearm(): void
    {
        $next = $this->nextDueAt();

        if ($next !== null) {
            $this->arm($next);
        }
    }

    /**
     * Makes sure a scheduler job will run at (or before) `$when`.
     *
     * Remembers the earliest armed time in the cache, so arming a thousand drip enrolments in one
     * import pushes one job, not a thousand. If the cache is lost, the worst case is an extra job.
     */
    public function arm(DateTime $when): void
    {
        $cache = Craft::$app->getCache();
        $at = $when->getTimestamp();
        $now = time();
        $armed = $cache->get(self::CACHE_KEY);

        if ($armed !== false && (int)$armed <= $at) {
            return;
        }

        $delay = max(0, $at - $now);
        Craft::$app->getQueue()->delay($delay)->push(new ProcessScheduleJob());

        // Held a little past the run time, so a job that was lost (queue cleared) stops
        // suppressing new ones soon after it should have run.
        $cache->set(self::CACHE_KEY, $at, $delay + 300);
    }

    /** "Now" as stored in Dispatch's datetime columns (UTC). */
    public static function dbNow(): string
    {
        return Db::prepareDateForDb(new DateTime());
    }
}
