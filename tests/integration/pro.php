<?php
/**
 * Dispatch 5.2 Pro checks — drip sequences, A/B subject tests, dynamic segments, outgoing
 * webhooks and the scheduler — through the real services and the harness database.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-dispatch/tests/integration/pro.php
 *
 * Nothing leaves the container: mail goes to Yii's file transport and is captured from the
 * mailer's after-send event, queue pushes are captured (and run by hand), and webhook HTTP goes
 * to a Guzzle MockHandler. The edition and settings are changed in memory only.
 */

$root = getcwd();
require $root . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
require __DIR__ . '/_fixtures.php';

use craft\helpers\Db;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use justinholtweb\dispatch\elements\Campaign;
use justinholtweb\dispatch\elements\Subscriber;
use justinholtweb\dispatch\models\Endpoint;
use justinholtweb\dispatch\models\Segment;
use justinholtweb\dispatch\models\Sequence;
use justinholtweb\dispatch\models\SequenceStep;
use justinholtweb\dispatch\queue\jobs\DeliverWebhookJob;
use justinholtweb\dispatch\queue\jobs\ProcessScheduleJob;
use justinholtweb\dispatch\queue\jobs\SendCampaignJob;
use justinholtweb\dispatch\services\Integrations;
use justinholtweb\dispatch\services\Scheduler;
use yii\mail\BaseMailer;
use yii\mail\MailEvent;

/** Captures queue pushes instead of queueing them, so the checks can run jobs themselves. */
class DpCaptureQueue extends yii\queue\Queue
{
    /** @var array<int, array{job: object, delay: int}> */
    public array $jobs = [];

    protected function pushMessage($payload, $ttr, $delay, $priority)
    {
        $this->jobs[] = ['job' => $this->serializer->unserialize($payload), 'delay' => (int)$delay];

        return (string)count($this->jobs);
    }

    public function status($id)
    {
        return self::STATUS_DONE;
    }

    /** Removes and returns the captured jobs of a class. */
    public function take(string $class): array
    {
        $taken = array_values(array_filter($this->jobs, fn($j) => $j['job'] instanceof $class));
        $this->jobs = array_values(array_filter($this->jobs, fn($j) => !$j['job'] instanceof $class));

        return $taken;
    }
}

$plugin = dp();
$originalEdition = $plugin->edition;
$plugin->edition = 'pro';
$settings = $plugin->getSettings();
$originalSettings = $settings->toArray();
$settings->enableTracking = true;

$realQueue = Craft::$app->getQueue();
$queue = new DpCaptureQueue();
Craft::$app->set('queue', $queue);
Craft::$app->getCache()->delete(Scheduler::CACHE_KEY);

// Mail: to disk, and remember who got what subject.
$mailer = Craft::$app->getMailer();
$mailer->useFileTransport = true;
$mailer->fileTransportPath = sys_get_temp_dir() . '/dp-check-mail';
$mails = [];
yii\base\Event::on(BaseMailer::class, BaseMailer::EVENT_AFTER_SEND, function(MailEvent $e) use (&$mails) {
    $to = (array)$e->message->getTo();
    $first = array_key_first($to);
    $mails[] = ['to' => is_int($first) ? $to[$first] : $first, 'subject' => $e->message->getSubject()];
});

sweep();

/** Runs a captured SendCampaignJob (or any job) in this process. */
function runJob(object $job): void
{
    global $queue;
    $job->execute($queue);
}

function sendRow(int $enrolmentId, int $stepId): ?array
{
    return (new craft\db\Query())->from('{{%dispatch_sequencesends}}')->where(['enrolmentId' => $enrolmentId, 'stepId' => $stepId])->one() ?: null;
}

function enrolment(int $sequenceId, int $subscriberId): ?array
{
    return (new craft\db\Query())->from('{{%dispatch_enrolments}}')->where(['sequenceId' => $sequenceId, 'subscriberId' => $subscriberId])->one() ?: null;
}

function makeSequence(string $suffix, array $attrs, array $steps): Sequence
{
    $seq = new Sequence(array_merge(['name' => DP_PREFIX . ' ' . $suffix, 'handle' => 'dpcheck' . preg_replace('/\W/', '', $suffix) . random_int(100, 999)], $attrs));
    $seq->steps = array_map(fn($s) => new SequenceStep($s), $steps);

    if (!dp()->sequences->save($seq)) {
        throw new RuntimeException('Sequence did not save: ' . json_encode($seq->getErrors()));
    }

    return $seq;
}

// ===============================================================================================
section('Dynamic segments');

$listA = makeList('seg a');
$listB = makeList('seg b');
$ann = makeSubscriber('segann', ['firstName' => 'Ann']);
$bob = makeSubscriber('segbob', ['firstName' => 'Bob']);
$cat = makeSubscriber('segcat', ['firstName' => 'Cat']);
$gone = makeSubscriber('seggone', ['status' => 'unsubscribed']);
foreach ([$ann, $bob, $gone] as $s) {
    $plugin->subscribers->subscribe($s->id, $listA->id);
}
$plugin->subscribers->subscribe($bob->id, $listB->id);
// Ann signed up long ago; Cat opened a campaign.
Db::update('{{%dispatch_subscribers}}', ['subscribedAt' => '2020-01-01 00:00:00'], ['id' => $ann->id]);
$opened = makeCampaign('seg opened', $listA);
$plugin->tracker->recordOpen($opened->id, $cat->id);

$segment = static function(string $suffix, array $rules): Segment {
    $s = new Segment(['name' => DP_PREFIX . ' ' . $suffix, 'handle' => 'dpcheck' . preg_replace('/\W/', '', $suffix) . random_int(100, 999), 'condition' => ['conditionRules' => $rules]]);
    if (!dp()->segments->save($s)) {
        throw new RuntimeException(json_encode($s->getErrors()));
    }

    return dp()->segments->getById($s->id);
};

$fixtureIds = array_map(fn($s) => (int)$s->id, [$ann, $bob, $cat, $gone]);
$ids = static fn(Segment $s, bool $active = false) => array_values(array_intersect(array_map('intval', dp()->segments->query($s, $active)->ids()), $fixtureIds));

check('Subscriber::createCondition() is the Dispatch condition, offering its own rules', function() {
    $condition = Subscriber::createCondition();
    $types = (fn() => array_map(fn($t) => is_array($t) ? $t['class'] : $t, $this->selectableConditionRules()))->call($condition);

    return $condition instanceof justinholtweb\dispatch\elements\conditions\SubscriberCondition
        && in_array(justinholtweb\dispatch\elements\conditions\MailingListConditionRule::class, $types, true)
        && in_array(justinholtweb\dispatch\elements\conditions\LastActivityConditionRule::class, $types, true);
});

check('on list A but not list B', function() use ($segment, $ids, $listA, $listB, $ann, $gone) {
    $s = $segment('a not b', [
        ['class' => justinholtweb\dispatch\elements\conditions\MailingListConditionRule::class, 'operator' => 'in', 'values' => [(string)$listA->id]],
        ['class' => justinholtweb\dispatch\elements\conditions\MailingListConditionRule::class, 'operator' => 'ni', 'values' => [(string)$listB->id]],
    ]);
    $got = $ids($s);
    sort($got);
    $want = [$ann->id, $gone->id];
    sort($want);

    return $got === $want ?: json_encode($got);
});

check('the stored condition survives the round trip (rules come back as rules)', function() use ($segment, $listA) {
    $s = $segment('roundtrip', [['class' => justinholtweb\dispatch\elements\conditions\MailingListConditionRule::class, 'operator' => 'in', 'values' => [(string)$listA->id]]]);

    return count($s->getCondition()->getConditionRules()) === 1;
});

check('signup date before 2021', function() use ($segment, $ids, $ann) {
    $s = $segment('old', [['class' => justinholtweb\dispatch\elements\conditions\SignupDateConditionRule::class, 'rangeType' => 'range', 'endDate' => '2021-01-01']]);

    return $ids($s) === [(int)$ann->id] ?: json_encode($ids($s));
});

check('opened a campaign / never opened anything / last activity empty', function() use ($segment, $ids, $opened, $cat, $ann) {
    $did = $segment('opened', [['class' => justinholtweb\dispatch\elements\conditions\OpenedCampaignConditionRule::class, 'operator' => 'in', 'values' => [(string)$opened->id]]]);
    $never = $segment('never', [['class' => justinholtweb\dispatch\elements\conditions\OpenedCampaignConditionRule::class, 'operator' => 'ni', 'values' => ['*']]]);
    $cold = $segment('cold', [['class' => justinholtweb\dispatch\elements\conditions\LastActivityConditionRule::class, 'rangeType' => 'empty']]);

    return $ids($did) === [(int)$cat->id] && !in_array((int)$cat->id, $ids($never), true) && in_array((int)$ann->id, $ids($never), true)
        && !in_array((int)$cat->id, $ids($cold), true) && in_array((int)$ann->id, $ids($cold), true)
        ?: json_encode([$ids($did), $ids($never), $ids($cold)]);
});

check('in-memory matching agrees with the query (matchElement)', function() use ($segment, $listA, $ann, $bob, $cat) {
    $s = $segment('match', [['class' => justinholtweb\dispatch\elements\conditions\MailingListConditionRule::class, 'operator' => 'in', 'values' => [(string)$listA->id]]]);
    $c = $s->getCondition();

    return $c->matchElement($ann) && $c->matchElement($bob) && !$c->matchElement($cat);
});

check('email rule: ends with', function() use ($segment, $ids, $bob) {
    $s = $segment('email', [['class' => justinholtweb\dispatch\elements\conditions\EmailConditionRule::class, 'operator' => 'ew', 'value' => substr((string)$bob->email, 7)]]);

    return $ids($s) === [(int)$bob->id] ?: json_encode($ids($s));
});

check('a campaign sent to list A + segment reaches only active matching subscribers', function() use ($segment, $listA, $listB, $ann, $bob, $gone, $queue, &$mails) {
    $notB = $segment('audience', [['class' => justinholtweb\dispatch\elements\conditions\MailingListConditionRule::class, 'operator' => 'ni', 'values' => [(string)$listB->id]]]);
    $c = makeCampaign('segment send', $listA);
    $c->segmentId = $notB->id;
    Craft::$app->getElements()->saveElement($c);
    $mails = [];

    if (!dp()->campaigns->send($c->id)) {
        return 'send() refused';
    }
    foreach ($queue->take(SendCampaignJob::class) as $j) {
        runJob($j['job']);
    }
    $to = array_column($mails, 'to');

    return $to === [$ann->email] && !in_array($gone->email, $to, true) && !in_array($bob->email, $to, true)
        && Campaign::find()->id($c->id)->status(null)->one()->campaignStatus === 'sent' ?: json_encode($to);
});

check('a segment-only campaign (no list) is allowed; one with neither is refused', function() use ($segment, $listA) {
    $s = $segment('only', [['class' => justinholtweb\dispatch\elements\conditions\MailingListConditionRule::class, 'operator' => 'in', 'values' => [(string)$listA->id]]]);
    $c = makeCampaign('segment only', null);
    $c->segmentId = $s->id;
    Craft::$app->getElements()->saveElement($c);
    $none = makeCampaign('no audience', null);

    return dp()->campaigns->audienceQuery($c) !== null && dp()->campaigns->audienceQuery($none) === null;
});

check('on a non-Pro site a segment campaign has no audience (never falls back to the whole list)', function() use ($segment, $listA, $plugin) {
    $s = $segment('lite', []);
    $c = makeCampaign('lite seg', $listA);
    $c->segmentId = $s->id;
    Craft::$app->getElements()->saveElement($c);
    $plugin->edition = 'lite';
    $q = dp()->campaigns->audienceQuery($c);
    $plugin->edition = 'pro';

    return $q === null;
});

check('Send claims the campaign: a second send() of the same campaign is refused', function() use ($listA, $queue) {
    $c = makeCampaign('double', $listA);
    $first = dp()->campaigns->send($c->id);
    $second = dp()->campaigns->send($c->id);
    $jobs = $queue->take(SendCampaignJob::class);

    return $first && !$second && count($jobs) === 1 ?: json_encode([$first, $second, count($jobs)]);
});

check('a retried send job skips subscribers already sent to', function() use ($listA, $queue, &$mails) {
    $c = makeCampaign('retry', $listA);
    dp()->campaigns->send($c->id);
    [$job] = $queue->take(SendCampaignJob::class);
    runJob($job['job']);
    $first = count($mails);
    $mails = [];
    Db::update('{{%dispatch_campaigns}}', ['campaignStatus' => 'sending'], ['id' => $c->id]);
    runJob($job['job']);

    return $first > 0 && $mails === [] ?: "first $first, retry " . count($mails);
});

// ===============================================================================================
section('Drip sequences');

$dripList = makeList('drip');
$step1 = makeCampaign('drip 1', null, '<p>Welcome {{ subscriber.firstName }}</p>');
$step2 = makeCampaign('drip 2', null, '<p>Day two</p>');
$seq = makeSequence('welcome', ['triggerType' => 'list', 'triggerListId' => $dripList->id], [
    ['campaignId' => $step1->id, 'delayMinutes' => 0],
    ['campaignId' => $step2->id, 'delayMinutes' => 1440],
]);
$steps = $plugin->sequences->getSteps($seq->id);
$queue->jobs = [];
Craft::$app->getCache()->delete(Scheduler::CACHE_KEY);

$dee = makeSubscriber('dripdee', ['firstName' => 'Dee']);
$plugin->subscribers->subscribe($dee->id, $dripList->id);
$deeEnrolment = enrolment($seq->id, $dee->id);

check('joining the list enrols the subscriber and materialises both steps', function() use ($deeEnrolment, $steps) {
    if (!$deeEnrolment) {
        return 'not enrolled';
    }
    $a = sendRow((int)$deeEnrolment['id'], (int)$steps[0]->id);
    $b = sendRow((int)$deeEnrolment['id'], (int)$steps[1]->id);
    $gap = strtotime($b['dueAt']) - strtotime($a['dueAt']);

    return $a && $b && $a['status'] === 'pending' && $b['status'] === 'pending' && abs($gap - 86400) < 5 ?: json_encode([$a, $b]);
});

check('the scheduler was armed for the first step (one job, no delay)', function() use ($queue) {
    $jobs = $queue->take(ProcessScheduleJob::class);

    return count($jobs) === 1 && $jobs[0]['delay'] === 0 ?: json_encode(array_column($jobs, 'delay'));
});

check('the scheduler sends the due step and leaves the next one pending', function() use ($deeEnrolment, $steps, $dee, &$mails) {
    $mails = [];
    $results = dp()->scheduler->run();
    $a = sendRow((int)$deeEnrolment['id'], (int)$steps[0]->id);
    $b = sendRow((int)$deeEnrolment['id'], (int)$steps[1]->id);

    return $results['sequenceSends'] >= 1 && $a['status'] === 'sent' && $b['status'] === 'pending'
        && in_array(['to' => $dee->email, 'subject' => 'Hi Dee'], $mails, true)
        ?: json_encode([$results, $a['status'], $b['status'], $mails]);
});

check('…and re-armed itself for the next step, about a day out', function() use ($queue) {
    $jobs = $queue->take(ProcessScheduleJob::class);

    return count($jobs) === 1 && abs($jobs[0]['delay'] - 86400) < 10 ?: json_encode(array_column($jobs, 'delay'));
});

check('running again sends nothing twice', function() use (&$mails) {
    $mails = [];
    dp()->sequences->processDue();

    return array_filter($mails, fn($m) => str_contains((string)$m['to'], 'dripdee')) === [];
});

check('a row another run has claimed is not sent', function() use ($deeEnrolment, $steps, &$mails) {
    $id = (int)sendRow((int)$deeEnrolment['id'], (int)$steps[1]->id)['id'];
    Db::update('{{%dispatch_sequencesends}}', ['status' => 'sending', 'dueAt' => '2000-01-01 00:00:00'], ['id' => $id], [], false);
    $mails = [];
    dp()->sequences->processDue();
    Db::update('{{%dispatch_sequencesends}}', ['status' => 'pending'], ['id' => $id], [], false);

    return $mails === [];
});

check('when the last step is due it is sent and the enrolment completes', function() use ($deeEnrolment, $steps, $seq, $dee) {
    dp()->sequences->processDue();
    $b = sendRow((int)$deeEnrolment['id'], (int)$steps[1]->id);

    return $b['status'] === 'sent' && enrolment($seq->id, $dee->id)['status'] === 'completed' ?: json_encode([$b['status'], enrolment($seq->id, $dee->id)['status']]);
});

check('leaving and rejoining the list does not enrol twice', function() use ($dee, $dripList, $seq) {
    dp()->subscribers->removeFromList($dee->id, $dripList->id);
    dp()->subscribers->subscribe($dee->id, $dripList->id);

    return (int)(new craft\db\Query())->from('{{%dispatch_enrolments}}')->where(['sequenceId' => $seq->id, 'subscriberId' => $dee->id])->count() === 1;
});

check('unsubscribing stops the sequence and cancels pending steps', function() use ($dripList, $seq, $steps) {
    $s = makeSubscriber('dripunsub');
    dp()->subscribers->subscribe($s->id, $dripList->id);
    dp()->subscribers->unsubscribe($s->id);
    $e = enrolment($seq->id, $s->id);

    return $e['status'] === 'stopped' && sendRow((int)$e['id'], (int)$steps[0]->id)['status'] === 'cancelled' ?: json_encode($e);
});

check('a bounce stops it too', function() use ($dripList, $seq, $step1) {
    $s = makeSubscriber('dripbounce');
    dp()->subscribers->subscribe($s->id, $dripList->id);
    dp()->tracker->recordBounce($step1->id, $s->id, 'mailbox full');

    return enrolment($seq->id, $s->id)['status'] === 'stopped';
});

check('leaving only the trigger list stops it (other lists untouched)', function() use ($dripList, $seq) {
    $s = makeSubscriber('dripleave');
    dp()->subscribers->subscribe($s->id, $dripList->id);
    dp()->subscribers->removeFromList($s->id, $dripList->id);

    return enrolment($seq->id, $s->id)['status'] === 'stopped' && subscriberStatus($s) === 'active';
});

check('a subscriber who already received a step campaign is skipped, not mailed again', function() use ($dripList, $seq, $steps, $step1, &$mails) {
    $s = makeSubscriber('dripdup');
    logSend($step1, $s, '<dup@dp.test>');
    dp()->subscribers->subscribe($s->id, $dripList->id);
    $mails = [];
    dp()->sequences->processDue();
    $e = enrolment($seq->id, $s->id);

    return sendRow((int)$e['id'], (int)$steps[0]->id)['status'] === 'skipped' && $mails === [];
});

check('a paused (disabled) sequence sends nothing and is not counted as due', function() use ($dripList, $seq, $steps, &$mails) {
    $seq->enabled = false;
    $seq->steps = dp()->sequences->getSteps($seq->id);
    dp()->sequences->save($seq);
    $s = makeSubscriber('drippaused');
    dp()->subscribers->subscribe($s->id, $dripList->id);
    $enrolled = enrolment($seq->id, $s->id);
    $seq->enabled = true;
    dp()->sequences->save($seq);

    return $enrolled === null ?: 'enrolled in a disabled sequence';
});

check('custom events enrol (by email); unknown emails and non-Pro sites do nothing', function() use ($plugin) {
    $c = makeCampaign('event step', null);
    $evSeq = makeSequence('event', ['triggerType' => 'event', 'triggerEvent' => 'dpcheck.order'], [['campaignId' => $c->id, 'delayMinutes' => 60]]);
    $s = makeSubscriber('dripevent');
    $n = dp()->sequences->fire('dpcheck.order', (string)$s->email);
    $again = dp()->sequences->fire('dpcheck.order', $s);
    $unknown = dp()->sequences->fire('dpcheck.order', 'nobody-' . random_int(1, 999) . '@example.test');
    $wildcard = dp()->sequences->fire('dpcheck.order', 'dpcheck*');
    $plugin->edition = 'lite';
    $lite = dp()->sequences->fire('dpcheck.order', makeSubscriber('driplite'));
    $plugin->edition = 'pro';

    return $n === 1 && $again === 0 && $unknown === 0 && $wildcard === 0 && $lite === 0 && enrolment($evSeq->id, $s->id) !== null
        ?: json_encode([$n, $again, $unknown, $wildcard, $lite]);
});

check('an EVENT_BEFORE_ENROL handler can veto', function() use ($dripList, $seq) {
    $h = fn($e) => $e->isValid = false;
    yii\base\Event::on(justinholtweb\dispatch\services\Sequences::class, justinholtweb\dispatch\services\Sequences::EVENT_BEFORE_ENROL, $h);
    $s = makeSubscriber('dripveto');
    dp()->subscribers->subscribe($s->id, $dripList->id);
    yii\base\Event::off(justinholtweb\dispatch\services\Sequences::class, justinholtweb\dispatch\services\Sequences::EVENT_BEFORE_ENROL, $h);

    return enrolment($seq->id, $s->id) === null;
});

check('on a non-Pro site due steps wait (stay pending) rather than send', function() use ($dripList, $seq, $steps, $plugin, &$mails) {
    $s = makeSubscriber('dripwait');
    dp()->subscribers->subscribe($s->id, $dripList->id);
    $plugin->edition = 'lite';
    $mails = [];
    $n = dp()->sequences->processDue();
    $plugin->edition = 'pro';
    $e = enrolment($seq->id, $s->id);

    return $n === 0 && $mails === [] && sendRow((int)$e['id'], (int)$steps[0]->id)['status'] === 'pending';
});

check('deleting a step from the sequence cancels it for everyone (rows go)', function() use ($seq, $steps) {
    $seq->steps = [dp()->sequences->getSteps($seq->id)[0]];
    dp()->sequences->save($seq);

    return !(new craft\db\Query())->from('{{%dispatch_sequencesends}}')->where(['stepId' => $steps[1]->id])->exists()
        && count(dp()->sequences->getSteps($seq->id)) === 1 && dp()->sequences->getSteps($seq->id)[0]->id === $steps[0]->id;
});

// ===============================================================================================
section('A/B subject tests');

check('the test plan: 20% of 100 over 2 variants is 10 + 10, the same every time', function() use ($listA) {
    $c = makeCampaign('plan', $listA);
    $c->setAbSubjects(['B']);
    $c->abTestPercent = 20;
    $ids = range(1, 100);
    $plan = dp()->abTesting->testPlan($c, $ids);
    $again = dp()->abTesting->testPlan($c, array_reverse($ids));

    return count($plan[0]) === 10 && count($plan[1]) === 10 && $plan === $again && !array_intersect($plan[0], $plan[1]);
});

check('a tiny audience still gets one recipient per variant', function() use ($listA) {
    $c = makeCampaign('tiny', $listA);
    $c->setAbSubjects(['B', 'C']);
    $c->abTestPercent = 1;
    $plan = dp()->abTesting->testPlan($c, [1, 2, 3, 4, 5]);

    return array_map('count', $plan) === [1, 1, 1];
});

check('enabling a test with no alternative subject fails validation', function() use ($listA) {
    $c = makeCampaign('no alt', $listA);
    $c->abEnabled = true;

    return !$c->validate() && $c->hasErrors('abSubjects');
});

$abList = makeList('ab');
$abSubs = [];
for ($i = 0; $i < 10; $i++) {
    $abSubs[] = $s = makeSubscriber('ab' . $i, ['firstName' => 'A' . $i]);
    $plugin->subscribers->subscribe($s->id, $abList->id);
}
$abCampaign = makeCampaign('ab test', $abList);
$abCampaign->subject = 'Subject A';
$abCampaign->abEnabled = true;
$abCampaign->setAbSubjects(['Subject B']);
$abCampaign->abTestPercent = 20;
$abCampaign->abWaitMinutes = 120;
Craft::$app->getElements()->saveElement($abCampaign);
$queue->jobs = [];
Craft::$app->getCache()->delete(Scheduler::CACHE_KEY);

check('sending starts the test phase: 2 of 10 get one subject each, recorded by variant', function() use ($abCampaign, $queue, &$mails) {
    $mails = [];
    dp()->campaigns->send($abCampaign->id);
    $jobs = $queue->take(SendCampaignJob::class);
    if (count($jobs) !== 1 || $jobs[0]['job']->phase !== 'test') {
        return 'expected one test-phase job';
    }
    runJob($jobs[0]['job']);
    $variants = (new craft\db\Query())->select('variant')->from('{{%dispatch_sendlog}}')->where(['campaignId' => $abCampaign->id])->orderBy('variant')->column();
    $subjects = array_column($mails, 'subject');
    sort($subjects);

    return array_map('intval', $variants) === [0, 1] && $subjects === ['Subject A', 'Subject B'] ?: json_encode([$variants, $subjects]);
});

check('…then waits: status waiting, winner time set, scheduler armed for it', function() use ($abCampaign, $queue) {
    $c = Campaign::find()->id($abCampaign->id)->status(null)->one();
    $jobs = $queue->take(ProcessScheduleJob::class);

    return $c->abStatus === 'waiting' && $c->campaignStatus === 'sending' && $c->abDecideAt
        && count($jobs) === 1 && abs($jobs[0]['delay'] - 7200) < 10 ?: json_encode([$c->abStatus, $c->abDecideAt, array_column($jobs, 'delay')]);
});

check('nothing is decided before the wait is over', function() {
    return dp()->abTesting->decideDue() === 0;
});

check('after the wait the variant with more opens wins and the rest get its subject', function() use ($abCampaign, $queue, &$mails) {
    $bRecipient = (int)(new craft\db\Query())->select('subscriberId')->from('{{%dispatch_sendlog}}')->where(['campaignId' => $abCampaign->id, 'variant' => 1])->scalar();
    dp()->tracker->recordOpen($abCampaign->id, $bRecipient);
    Db::update('{{%dispatch_campaigns}}', ['abDecideAt' => '2000-01-01 00:00:00'], ['id' => $abCampaign->id]);
    $results = dp()->scheduler->run();
    $jobs = $queue->take(SendCampaignJob::class);
    if ($results['abTests'] !== 1 || count($jobs) !== 1 || $jobs[0]['job']->phase !== 'remainder') {
        return json_encode([$results, count($jobs)]);
    }
    $mails = [];
    runJob($jobs[0]['job']);
    $c = Campaign::find()->id($abCampaign->id)->status(null)->one();
    $subjects = array_unique(array_column($mails, 'subject'));

    return $c->abWinner === 1 && $c->abStatus === 'decided' && count($mails) === 8 && $subjects === ['Subject B']
        && $c->campaignStatus === 'sent' && $c->totalSent === 10
        ?: json_encode([$c->abWinner, $c->abStatus, count($mails), $subjects, $c->campaignStatus, $c->totalSent]);
});

check('results are per variant, from the test sends only, with the winner flagged', function() use ($abCampaign) {
    $c = Campaign::find()->id($abCampaign->id)->status(null)->one();
    $r = dp()->abTesting->results($c);

    return $r[0]['sent'] === 1 && $r[1]['sent'] === 1 && $r[1]['opens'] === 1 && $r[1]['openRate'] === 1.0
        && $r[1]['winner'] && !$r[0]['winner'] ?: json_encode($r);
});

check('a second decide is a no-op (claimed)', function() use ($abCampaign) {
    return !dp()->abTesting->decide($abCampaign->id);
});

check('ties go to variant A; the click metric is honoured', function() use ($abList) {
    $c = makeCampaign('tie', $abList);
    $c->setAbSubjects(['B']);
    $tie = dp()->abTesting->pickWinner($c);

    $d = makeCampaign('clicks', $abList);
    $d->setAbSubjects(['B']);
    $d->abMetric = 'clicks';
    [$s0, $s1] = [makeSubscriber('clk0'), makeSubscriber('clk1')];
    foreach ([[$s0, 0], [$s1, 1]] as [$s, $v]) {
        logSend($d, $s, "<clk{$v}@dp.test>");
        Db::update('{{%dispatch_sendlog}}', ['variant' => $v], ['campaignId' => $d->id, 'subscriberId' => $s->id]);
    }
    dp()->tracker->recordOpen($d->id, $s0->id);
    dp()->tracker->recordClick($d->id, $s1->id, 'https://example.com/');

    return $tie === 0 && dp()->abTesting->pickWinner($d) === 1;
});

check('A/B is Pro: on Lite the campaign sends normally to everyone', function() use ($abList, $plugin, $queue) {
    $c = makeCampaign('ab lite', $abList);
    $c->abEnabled = true;
    $c->setAbSubjects(['B']);
    Craft::$app->getElements()->saveElement($c);
    $plugin->edition = 'lite';
    dp()->campaigns->send($c->id);
    $plugin->edition = 'pro';
    $jobs = $queue->take(SendCampaignJob::class);

    return count($jobs) === 1 && $jobs[0]['job']->phase === 'all';
});

// ===============================================================================================
section('Scheduler');

check('arm() pushes one job for the earliest time and ignores later ones', function() use ($queue) {
    Craft::$app->getCache()->delete(Scheduler::CACHE_KEY);
    $queue->jobs = [];
    $s = dp()->scheduler;
    $s->arm(new DateTime('+1 hour'));
    $s->arm(new DateTime('+2 hours'));
    $s->arm(new DateTime('+30 minutes'));
    $delays = array_column($queue->take(ProcessScheduleJob::class), 'delay');

    return count($delays) === 2 && abs($delays[0] - 3600) < 5 && abs($delays[1] - 1800) < 5 ?: json_encode($delays);
});

check('a scheduled campaign whose time has passed is started by the scheduler', function() use ($listA, $queue) {
    $c = makeCampaign('scheduled', $listA);
    dp()->campaigns->schedule($c->id, new DateTime('-1 minute'));
    $results = dp()->scheduler->run();
    $jobs = $queue->take(SendCampaignJob::class);

    return $results['campaigns'] >= 1 && Campaign::find()->id($c->id)->status(null)->one()->campaignStatus === 'sending'
        && count($jobs) >= 1 ?: json_encode($results);
});

check('a scheduled campaign in the future is left alone, but is the next due time', function() use ($listA) {
    $c = makeCampaign('future', $listA);
    $at = new DateTime('+3 days');
    dp()->campaigns->schedule($c->id, $at);
    dp()->scheduler->run();
    $next = dp()->scheduler->nextDueAt();
    $status = Campaign::find()->id($c->id)->status(null)->one()->campaignStatus;
    Db::update('{{%dispatch_campaigns}}', ['campaignStatus' => 'draft'], ['id' => $c->id]);

    return $status === 'scheduled' && $next && $next <= $at;
});

// ===============================================================================================
section('Outgoing webhooks (Integrations)');

$history = [];
$mock = new MockHandler();
$stack = HandlerStack::create($mock);
$stack->push(Middleware::history($history));
$integrations = $plugin->integrations;
$integrations->clientFactory = fn() => new Client(['handler' => $stack]);
$integrations->resolver = fn(string $host) => $host === 'hooks.example.com' ? ['93.184.216.34'] : ($host === 'internal.example.com' ? ['10.0.0.7'] : []);

$endpoint = new Endpoint([
    'name' => DP_PREFIX . ' hook',
    'url' => 'https://hooks.example.com/dispatch',
    'secret' => 'whsec_dpcheck',
    'events' => [Endpoint::EVENT_SUBSCRIBED, Endpoint::EVENT_CLICKED],
]);

check('an endpoint needs a URL, secret and at least one known event', function() {
    $e = new Endpoint(['name' => 'x', 'url' => 'ftp://x.test/', 'secret' => '', 'events' => ['nope']]);

    return !$e->validate() && $e->hasErrors('url') && $e->hasErrors('secret') && $e->hasErrors('events');
});

check('the endpoint saves', fn() => $integrations->saveEndpoint($endpoint) ?: json_encode($endpoint->getErrors()));

check('SSRF guard refuses loopback, metadata, mapped-IPv6, private DNS, credentials and non-http', function() use ($integrations) {
    foreach ([
        'http://127.0.0.1/x', 'http://169.254.169.254/latest/meta-data', 'http://[::ffff:127.0.0.1]/x',
        'https://internal.example.com/x', 'https://unresolvable.example.com/', 'https://user:pw@hooks.example.com/',
        'ftp://hooks.example.com/', 'file:///etc/passwd', 'http://0.0.0.0/', 'http://[::1]/',
    ] as $url) {
        if (!is_string($integrations->target($url))) {
            return "allowed $url";
        }
    }

    return is_array($integrations->target('https://hooks.example.com/dispatch'));
});

check('…and with real resolution (no test resolver), IP literals are judged by Ip::isPublic', function() use ($integrations) {
    $resolver = $integrations->resolver;
    $integrations->resolver = null;
    $refused = array_map(fn($u) => is_string($integrations->target($u)), ['http://127.0.0.1/', 'http://10.1.2.3/', 'http://[fd00::1]/', 'http://100.64.0.1/', 'http://[::ffff:169.254.169.254]/']);
    $public = $integrations->target('http://93.184.216.34/');
    $integrations->resolver = $resolver;

    return !in_array(false, $refused, true) && is_array($public) && $public['addresses'] === ['93.184.216.34'] ?: json_encode([$refused, $public]);
});

check('subscribing queues one signed delivery for the endpoint (and none for events it did not choose)', function() use ($listA, $queue, $endpoint) {
    $queue->jobs = [];
    $s = makeSubscriber('hook');
    dp()->subscribers->subscribe($s->id, $listA->id);
    dp()->subscribers->unsubscribe($s->id, $listA->id);
    $jobs = $queue->take(DeliverWebhookJob::class);
    $rows = (new craft\db\Query())->from('{{%dispatch_deliveries}}')->where(['endpointId' => $endpoint->id])->all();

    return count($jobs) === 1 && count($rows) === 1 && $rows[0]['event'] === 'subscriber.subscribed' && $rows[0]['status'] === 'pending'
        ?: json_encode([count($jobs), array_column($rows, 'event')]);
});

check('delivery POSTs JSON with a verifiable HMAC signature, pinned to the checked address', function() use ($integrations, $mock, &$history, $endpoint) {
    $id = (int)(new craft\db\Query())->select('id')->from('{{%dispatch_deliveries}}')->where(['endpointId' => $endpoint->id])->scalar();
    $mock->append(new Response(200, [], 'ok'));
    $history = [];
    $ok = $integrations->deliver($id);
    $req = $history[0]['request'];
    $opts = $history[0]['options'];
    $body = (string)$req->getBody();
    preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', $req->getHeaderLine('X-Dispatch-Signature'), $m);
    $valid = $m && hash_equals(hash_hmac('sha256', $m[1] . '.' . $body, 'whsec_dpcheck'), $m[2]);
    $payload = json_decode($body, true);
    $row = (new craft\db\Query())->from('{{%dispatch_deliveries}}')->where(['id' => $id])->one();

    return $ok && $valid && $payload['event'] === 'subscriber.subscribed' && isset($payload['data']['subscriber']['email'])
        && $req->getHeaderLine('X-Dispatch-Delivery') === $payload['id']
        && in_array('hooks.example.com:443:93.184.216.34', $opts['curl'][CURLOPT_RESOLVE] ?? [], true)
        && $opts['allow_redirects'] === false && $row['status'] === 'success' && (int)$row['responseCode'] === 200
        ?: json_encode([$ok, $valid, $payload['event'] ?? null, $opts['curl'] ?? null, $row['status']]);
});

check('a delivery that already succeeded is not sent again', function() use ($integrations, &$history, $endpoint) {
    $id = (int)(new craft\db\Query())->select('id')->from('{{%dispatch_deliveries}}')->where(['endpointId' => $endpoint->id])->scalar();
    $history = [];

    return !$integrations->deliver($id) && $history === [];
});

check('a 500 is retried with backoff; after the last attempt it is failed', function() use ($integrations, $mock, $queue, $endpoint) {
    $id = $integrations->queueDelivery($endpoint, Endpoint::EVENT_CLICKED, ['url' => 'https://example.com']);
    $queue->take(DeliverWebhookJob::class);
    $delays = [];

    for ($i = 1; $i <= Integrations::MAX_ATTEMPTS; $i++) {
        $mock->append(new Response(500, [], 'boom'));
        $integrations->deliver($id);
        foreach ($queue->take(DeliverWebhookJob::class) as $j) {
            $delays[] = $j['delay'];
        }
    }
    $row = (new craft\db\Query())->from('{{%dispatch_deliveries}}')->where(['id' => $id])->one();

    return $delays === Integrations::BACKOFF && $row['status'] === 'failed' && (int)$row['attempts'] === Integrations::MAX_ATTEMPTS
        ?: json_encode([$delays, $row['status'], $row['attempts']]);
});

check('redeliver resets a failed delivery and queues it; success then sticks', function() use ($integrations, $mock, $queue, $endpoint) {
    $id = (int)(new craft\db\Query())->select('id')->from('{{%dispatch_deliveries}}')->where(['endpointId' => $endpoint->id, 'status' => 'failed'])->scalar();
    $ok = $integrations->redeliver($id);
    $jobs = $queue->take(DeliverWebhookJob::class);
    $mock->append(new Response(204));
    $integrations->deliver($id);

    return $ok && count($jobs) === 1 && (new craft\db\Query())->select('status')->from('{{%dispatch_deliveries}}')->where(['id' => $id])->scalar() === 'success';
});

check('a URL the guard refuses fails at once, without retries or a request', function() use ($integrations, $queue, &$history) {
    $bad = new Endpoint(['name' => DP_PREFIX . ' internal', 'url' => 'https://internal.example.com/hook', 'secret' => 's', 'events' => [Endpoint::EVENT_SUBSCRIBED]]);
    $integrations->saveEndpoint($bad);
    $id = $integrations->queueDelivery($bad, Endpoint::EVENT_SUBSCRIBED, []);
    $queue->take(DeliverWebhookJob::class);
    $history = [];
    $integrations->deliver($id);
    $row = (new craft\db\Query())->from('{{%dispatch_deliveries}}')->where(['id' => $id])->one();

    $integrations->deleteEndpoint($bad->id);

    return $history === [] && $row['status'] === 'failed' && $queue->take(DeliverWebhookJob::class) === [] && str_contains((string)$row['error'], 'public');
});

check('the secret may be an environment variable', function() use ($integrations, $mock, &$history) {
    $_SERVER['DP_CHECK_HOOK'] = 'from-env';
    putenv('DP_CHECK_HOOK=from-env');
    $e = new Endpoint(['id' => 0, 'name' => 'env', 'url' => 'https://hooks.example.com/x', 'secret' => '$DP_CHECK_HOOK', 'events' => [Endpoint::EVENT_CLICKED]]);
    $mock->append(new Response(200));
    $history = [];
    $integrations->post($e, 'ping', 'x', '{}');
    putenv('DP_CHECK_HOOK');
    unset($_SERVER['DP_CHECK_HOOK']);
    preg_match('/^t=(\d+),v1=(\w+)$/', $history[0]['request']->getHeaderLine('X-Dispatch-Signature'), $m);

    return $m && hash_equals(hash_hmac('sha256', $m[1] . '.{}', 'from-env'), $m[2]);
});

check('click tracking feeds the email.clicked event, with the URL', function() use ($queue, $endpoint, $listA) {
    $queue->jobs = [];
    $c = makeCampaign('hook click', $listA);
    $s = makeSubscriber('hookclick');
    dp()->tracker->recordClick($c->id, $s->id, 'https://example.com/landing');
    $payload = json_decode((string)(new craft\db\Query())->select('payload')->from('{{%dispatch_deliveries}}')
        ->where(['endpointId' => $endpoint->id, 'event' => 'email.clicked'])->orderBy(['id' => SORT_DESC])->scalar(), true);

    return ($payload['data']['url'] ?? null) === 'https://example.com/landing' && ($payload['data']['campaign']['id'] ?? null) === (int)$c->id
        && count($queue->take(DeliverWebhookJob::class)) === 1;
});

check('disabled endpoints and non-Pro sites send nothing', function() use ($integrations, $endpoint, $plugin) {
    $plugin->edition = 'lite';
    $lite = $integrations->dispatch(Endpoint::EVENT_SUBSCRIBED, []);
    $plugin->edition = 'pro';
    $endpoint->enabled = false;
    $integrations->saveEndpoint($endpoint);
    $off = $integrations->dispatch(Endpoint::EVENT_SUBSCRIBED, []);
    $endpoint->enabled = true;
    $integrations->saveEndpoint($endpoint);

    return $lite === 0 && $off === 0;
});

check('an EVENT_BEFORE_DISPATCH handler can change the payload or cancel', function() use ($integrations) {
    $h = function($e) {
        $e->payload['extra'] = 'added';
    };
    yii\base\Event::on(Integrations::class, Integrations::EVENT_BEFORE_DISPATCH, $h);
    $integrations->dispatch(Endpoint::EVENT_CLICKED, ['url' => 'x']);
    yii\base\Event::off(Integrations::class, Integrations::EVENT_BEFORE_DISPATCH, $h);
    $payload = json_decode((string)(new craft\db\Query())->select('payload')->from('{{%dispatch_deliveries}}')->orderBy(['id' => SORT_DESC])->scalar(), true);

    $cancel = fn($e) => $e->isValid = false;
    yii\base\Event::on(Integrations::class, Integrations::EVENT_BEFORE_DISPATCH, $cancel);
    $n = $integrations->dispatch(Endpoint::EVENT_CLICKED, []);
    yii\base\Event::off(Integrations::class, Integrations::EVENT_BEFORE_DISPATCH, $cancel);

    return ($payload['data']['extra'] ?? null) === 'added' && $n === 0;
});

check('the delivery log is pruned by age (garbage collection)', function() use ($integrations, $endpoint) {
    $id = $integrations->queueDelivery($endpoint, Endpoint::EVENT_CLICKED, []);
    Db::update('{{%dispatch_deliveries}}', ['dateCreated' => '2000-01-01 00:00:00'], ['id' => $id], [], false);
    dp()->collectGarbage();

    return !(new craft\db\Query())->from('{{%dispatch_deliveries}}')->where(['id' => $id])->exists();
});

// ===============================================================================================
section('Subscriber custom fields (field layout + segment rule)');

// Borrows an existing plain-text field from the harness for a subscriber field layout, through
// project config like the settings screen does, then removes it again.
$textField = null;
foreach (Craft::$app->getFields()->getAllFields() as $f) {
    if ($f instanceof craft\fields\PlainText) {
        $textField = $f;
        break;
    }
}

if (!$textField) {
    echo "  (skipped: no plain-text field in this install)\n";
} else {
    $pc = Craft::$app->getProjectConfig();
    $layout = new craft\models\FieldLayout(['type' => Subscriber::class]);
    $tab = new craft\models\FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);
    $tab->setElements([new craft\fieldlayoutelements\CustomField($textField)]);
    $layout->setTabs([$tab]);
    $layoutUid = craft\helpers\StringHelper::UUID();

    try {
        $pc->set(justinholtweb\dispatch\Plugin::PC_SUBSCRIBER_FIELD_LAYOUT, [$layoutUid => $layout->getConfig()]);

        check('the layout from project config becomes the subscriber field layout', function() use ($textField) {
            $fl = (new Subscriber())->getFieldLayout();

            return $fl && $fl->id && $fl->getFieldByHandle($textField->handle) !== null;
        });

        check('a subscriber stores a custom field value', function() use ($textField) {
            $s = makeSubscriber('custom');
            $s->setFieldValue($textField->handle, 'gold');
            Craft::$app->getElements()->saveElement($s);
            $fresh = Subscriber::find()->id($s->id)->status(null)->one();

            return $fresh->getFieldValue($textField->handle) === 'gold';
        });

        check('the segment builder offers the custom field as a rule, and it filters', function() use ($textField, $segment) {
            $condition = Subscriber::createCondition();
            $types = (fn() => $this->selectableConditionRules())->call($condition);
            $rule = null;
            foreach ($types as $t) {
                if (is_array($t) && ($t['fieldUid'] ?? null) === $textField->uid) {
                    $rule = $t;
                }
            }
            if (!$rule) {
                return 'no rule for the field';
            }
            $s = $segment('custom', [$rule + ['operator' => '=', 'value' => 'gold']]);
            $emails = array_map(fn($x) => $x->email, dp()->segments->query($s)->email('dpcheck*')->all());

            return count($emails) === 1 && str_starts_with((string)$emails[0], 'dpcheckcustom') ?: json_encode($emails);
        });
    } finally {
        $pc->remove(justinholtweb\dispatch\Plugin::PC_SUBSCRIBER_FIELD_LAYOUT);
        $pc->saveModifiedConfigData();
        $pc->writeYamlFiles(true);
    }

    check('removing it from project config removes the layout', function() {
        return !(new Subscriber())->getFieldLayout()->id;
    });
}

// -----------------------------------------------------------------------------------------------
sweep();
Craft::$app->set('queue', $realQueue);
Craft::$app->getCache()->delete(Scheduler::CACHE_KEY);
$plugin->edition = $originalEdition;
$settings->setAttributes($originalSettings, false);

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
