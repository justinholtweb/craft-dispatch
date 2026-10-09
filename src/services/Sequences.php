<?php

namespace justinholtweb\dispatch\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateInterval;
use DateTime;
use justinholtweb\dispatch\elements\Campaign;
use justinholtweb\dispatch\elements\Subscriber;
use justinholtweb\dispatch\events\EnrolEvent;
use justinholtweb\dispatch\events\RegisterTriggerEventsEvent;
use justinholtweb\dispatch\models\Edition;
use justinholtweb\dispatch\models\Sequence;
use justinholtweb\dispatch\models\SequenceStep;
use justinholtweb\dispatch\Plugin;
use justinholtweb\dispatch\records\SendLogRecord;
use justinholtweb\dispatch\records\SubscriptionRecord;
use yii\db\Expression;
use yii\db\IntegrityException;

/**
 * Drip sequences (Pro): a trigger enrols a subscriber, and the sequence's campaigns go to them
 * one by one, each a set delay after the last.
 *
 * ## How a sequence runs
 *
 * 1. **Trigger.** Joining the sequence's mailing list, or a custom event fired with
 *    `Plugin::getInstance()->sequences->fire('order.completed', $emailOrSubscriber)` (or the REST
 *    API's `event` endpoint).
 * 2. **Enrolment.** One row per subscriber per sequence, ever — joining the list twice does not
 *    restart it. Every step is materialised at once as a `dispatch_sequencesends` row with the
 *    time it falls due, and the scheduler is armed for the first one.
 * 3. **Sending.** `Scheduler` runs `processDue()`, which claims each due row (pending → sending,
 *    a conditional UPDATE) before sending it, so a step can never go out twice — not from
 *    overlapping runs, not from a retried job. A subscriber is also never sent the same campaign
 *    twice by anything (the send log is unique per campaign and subscriber).
 * 4. **Stopping.** Unsubscribing, bouncing or complaining stops every active enrolment and
 *    cancels its pending steps; leaving a list stops that list's sequences. The subscriber's state
 *    is checked again at send time.
 *
 * Steps are materialised at enrolment, so editing a sequence's steps changes it for people who
 * enrol afterwards; deleting a step cancels it for everyone. Disabling a sequence pauses it —
 * re-enabling sends whatever fell due meanwhile.
 */
class Sequences extends Component
{
    /** @event RegisterTriggerEventsEvent Name your custom trigger events for the sequence editor. */
    public const EVENT_REGISTER_TRIGGER_EVENTS = 'registerTriggerEvents';

    /** @event EnrolEvent Before a subscriber is enrolled; set `$isValid = false` to skip. */
    public const EVENT_BEFORE_ENROL = 'beforeEnrol';

    /** @event EnrolEvent After a subscriber has been enrolled. */
    public const EVENT_AFTER_ENROL = 'afterEnrol';

    private const SEQUENCES = '{{%dispatch_sequences}}';
    private const STEPS = '{{%dispatch_sequencesteps}}';
    private const ENROLMENTS = '{{%dispatch_enrolments}}';
    private const SENDS = '{{%dispatch_sequencesends}}';

    // -------------------------------------------------------------------------------------------
    // Sequences and steps
    // -------------------------------------------------------------------------------------------

    /** @return Sequence[] */
    public function getAll(): array
    {
        return array_map(fn(array $row) => $this->_model($row), $this->_query()->orderBy(['name' => SORT_ASC])->all());
    }

    public function getById(int $id): ?Sequence
    {
        $row = $this->_query()->where(['id' => $id])->one();

        return $row ? $this->_model($row) : null;
    }

    public function getByHandle(string $handle): ?Sequence
    {
        $row = $this->_query()->where(['handle' => $handle])->one();

        return $row ? $this->_model($row) : null;
    }

    /** @return SequenceStep[] */
    public function getSteps(int $sequenceId): array
    {
        $rows = (new Query())
            ->select(['id', 'sequenceId', 'campaignId', 'sortOrder', 'delayMinutes'])
            ->from(self::STEPS)
            ->where(['sequenceId' => $sequenceId])
            ->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        return array_map(static fn(array $row) => new SequenceStep($row), $rows);
    }

    /**
     * Saves a sequence and its steps. Steps keep their IDs when they are kept (so materialised
     * sends stay attached); steps left out are deleted, cancelling their pending sends.
     */
    public function save(Sequence $sequence): bool
    {
        if (!$sequence->validate()) {
            return false;
        }

        $clash = (new Query())->from(self::SEQUENCES)->where(['handle' => $sequence->handle])
            ->andFilterWhere(['not', ['id' => $sequence->id]])->exists();

        if ($clash) {
            $sequence->addError('handle', Craft::t('dispatch', 'That handle is already in use.'));
            return false;
        }

        $data = [
            'name' => $sequence->name,
            'handle' => $sequence->handle,
            'triggerType' => $sequence->triggerType,
            'triggerListId' => $sequence->triggerType === Sequence::TRIGGER_LIST ? $sequence->triggerListId : null,
            'triggerEvent' => $sequence->triggerType === Sequence::TRIGGER_EVENT ? $sequence->triggerEvent : null,
            'enabled' => $sequence->enabled,
        ];

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            if ($sequence->id) {
                Db::update(self::SEQUENCES, $data, ['id' => $sequence->id]);
            } else {
                $data['uid'] = StringHelper::UUID();
                Db::insert(self::SEQUENCES, $data);
                $sequence->id = (int)Craft::$app->getDb()->getLastInsertID();
                $sequence->uid = $data['uid'];
            }

            $keep = [];

            foreach (array_values($sequence->steps) as $i => $step) {
                $row = [
                    'sequenceId' => $sequence->id,
                    'campaignId' => $step->campaignId,
                    'sortOrder' => $i,
                    'delayMinutes' => $step->delayMinutes,
                ];

                $exists = $step->id && (new Query())->from(self::STEPS)
                    ->where(['id' => $step->id, 'sequenceId' => $sequence->id])->exists();

                if ($exists) {
                    Db::update(self::STEPS, $row, ['id' => $step->id]);
                } else {
                    Db::insert(self::STEPS, $row);
                    $step->id = (int)Craft::$app->getDb()->getLastInsertID();
                }

                $step->sequenceId = $sequence->id;
                $step->sortOrder = $i;
                $keep[] = $step->id;
            }

            Db::delete(self::STEPS, ['and', ['sequenceId' => $sequence->id], ['not', ['id' => $keep ?: [0]]]]);

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        if ($sequence->enabled) {
            // Re-enabling a paused sequence: whatever fell due meanwhile goes out now.
            Plugin::getInstance()->scheduler->rearm();
        }

        return true;
    }

    public function delete(int $id): bool
    {
        // Steps, enrolments and pending sends go with it (foreign keys).
        return Db::delete(self::SEQUENCES, ['id' => $id]) > 0;
    }

    /**
     * The custom events the sequence editor offers as triggers, as registered by plugins and
     * modules. Any other key can still be typed in.
     *
     * @return array<string, string>
     */
    public function getTriggerEvents(): array
    {
        $event = new RegisterTriggerEventsEvent();
        $this->trigger(self::EVENT_REGISTER_TRIGGER_EVENTS, $event);

        return $event->events;
    }

    // -------------------------------------------------------------------------------------------
    // Triggers and enrolment
    // -------------------------------------------------------------------------------------------

    /**
     * Fires a custom trigger event: enrols the subscriber in every enabled sequence listening for
     * it. Other plugins call this, e.g. on a completed Commerce order:
     *
     *     Plugin::getInstance()->sequences->fire('order.completed', $order->email);
     *
     * Accepts a Subscriber, a subscriber ID or an email address (an unknown email is not added).
     *
     * @return int How many sequences the subscriber was enrolled in.
     */
    public function fire(string $eventKey, Subscriber|int|string $subscriber): int
    {
        if (!Edition::isPro()) {
            return 0;
        }

        $subscriber = $this->_resolveSubscriber($subscriber);

        if (!$subscriber) {
            return 0;
        }

        $enrolled = 0;

        foreach ($this->_enabledSequences(['triggerType' => Sequence::TRIGGER_EVENT, 'triggerEvent' => $eventKey]) as $sequence) {
            if ($this->enrol($sequence, $subscriber, $eventKey) !== null) {
                $enrolled++;
            }
        }

        return $enrolled;
    }

    /** A subscriber joined a list: enrol them in the sequences that list triggers. */
    public function handleListJoin(int $subscriberId, int $listId): int
    {
        if (!Edition::isPro()) {
            return 0;
        }

        $sequences = $this->_enabledSequences(['triggerType' => Sequence::TRIGGER_LIST, 'triggerListId' => $listId]);

        if ($sequences === []) {
            return 0;
        }

        $subscriber = $this->_resolveSubscriber($subscriberId);

        if (!$subscriber) {
            return 0;
        }

        $enrolled = 0;

        foreach ($sequences as $sequence) {
            if ($this->enrol($sequence, $subscriber, Sequence::TRIGGER_LIST) !== null) {
                $enrolled++;
            }
        }

        return $enrolled;
    }

    /**
     * Enrols a subscriber and materialises their steps. Returns the enrolment ID, or null when
     * they were not enrolled: not active, already enrolled once, no steps, or vetoed by an
     * `EVENT_BEFORE_ENROL` handler.
     */
    public function enrol(Sequence $sequence, Subscriber $subscriber, string $trigger = ''): ?int
    {
        if (!$sequence->id || $subscriber->status !== 'active') {
            return null;
        }

        $steps = $this->getSteps($sequence->id);

        if ($steps === []) {
            return null;
        }

        if ((new Query())->from(self::ENROLMENTS)->where(['sequenceId' => $sequence->id, 'subscriberId' => $subscriber->id])->exists()) {
            return null;
        }

        $event = new EnrolEvent(['sequence' => $sequence, 'subscriber' => $subscriber, 'trigger' => $trigger]);
        $this->trigger(self::EVENT_BEFORE_ENROL, $event);

        if (!$event->isValid) {
            return null;
        }

        $now = new DateTime();
        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            Db::insert(self::ENROLMENTS, [
                'sequenceId' => $sequence->id,
                'subscriberId' => $subscriber->id,
                'status' => 'active',
                'enrolledAt' => Db::prepareDateForDb($now),
            ]);
            $enrolmentId = (int)$db->getLastInsertID();

            $due = clone $now;
            $rows = [];

            foreach ($steps as $step) {
                $due = (clone $due)->add(new DateInterval('PT' . max(0, $step->delayMinutes) . 'M'));
                $rows[] = [$enrolmentId, $step->id, $subscriber->id, $step->campaignId, Db::prepareDateForDb($due), 'pending'];
            }

            Db::batchInsert(self::SENDS, ['enrolmentId', 'stepId', 'subscriberId', 'campaignId', 'dueAt', 'status'], $rows);

            $transaction->commit();
        } catch (IntegrityException) {
            // Enrolled by a concurrent request a moment ago — the unique index says once only.
            $transaction->rollBack();
            return null;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $first = (clone $now)->add(new DateInterval('PT' . max(0, $steps[0]->delayMinutes) . 'M'));
        Plugin::getInstance()->scheduler->arm($first);

        $this->trigger(self::EVENT_AFTER_ENROL, new EnrolEvent(['sequence' => $sequence, 'subscriber' => $subscriber, 'trigger' => $trigger]));

        return $enrolmentId;
    }

    /**
     * Stops a subscriber's active enrolments and cancels their pending steps. With `$listId`, only
     * the sequences that list triggers (they left that list, not everything).
     *
     * @return int Enrolments stopped.
     */
    public function stopForSubscriber(int $subscriberId, string $reason, ?int $listId = null): int
    {
        $query = (new Query())
            ->select(['e.id'])
            ->from(['e' => self::ENROLMENTS])
            ->where(['e.subscriberId' => $subscriberId, 'e.status' => 'active']);

        if ($listId !== null) {
            $query->innerJoin(['q' => self::SEQUENCES], '[[q.id]] = [[e.sequenceId]]')
                ->andWhere(['q.triggerType' => Sequence::TRIGGER_LIST, 'q.triggerListId' => $listId]);
        }

        $ids = array_map('intval', $query->column());

        if ($ids === []) {
            return 0;
        }

        Db::update(self::SENDS, ['status' => 'cancelled', 'error' => $reason], ['enrolmentId' => $ids, 'status' => 'pending'], [], false);
        Db::update(self::ENROLMENTS, ['status' => 'stopped', 'stopReason' => mb_substr($reason, 0, 255)], ['id' => $ids]);

        return count($ids);
    }

    // -------------------------------------------------------------------------------------------
    // Sending
    // -------------------------------------------------------------------------------------------

    /**
     * Sends the drip emails that are due, oldest first, at most `$limit`.
     *
     * @return int Rows processed (sent, skipped, cancelled or failed).
     */
    public function processDue(int $limit = 200): int
    {
        if (!Edition::isPro()) {
            // Paused, not lost: the rows stay pending and go out if the site is Pro again.
            return 0;
        }

        $rows = (new Query())
            ->select(['s.id', 's.enrolmentId', 's.subscriberId', 's.campaignId', 'e.status AS enrolmentStatus', 'q.triggerType', 'q.triggerListId'])
            ->from(['s' => self::SENDS])
            ->innerJoin(['e' => self::ENROLMENTS], '[[e.id]] = [[s.enrolmentId]]')
            ->innerJoin(['q' => self::SEQUENCES], '[[q.id]] = [[e.sequenceId]]')
            ->where(['s.status' => 'pending', 'q.enabled' => true])
            ->andWhere(['<=', 's.dueAt', Scheduler::dbNow()])
            ->orderBy(['s.dueAt' => SORT_ASC, 's.id' => SORT_ASC])
            ->limit($limit)
            ->all();

        $processed = 0;

        foreach ($rows as $row) {
            // The claim. Only the run whose UPDATE changes the row sends it.
            $claimed = Db::update(self::SENDS, ['status' => 'sending'], ['id' => $row['id'], 'status' => 'pending'], [], false);

            if ($claimed !== 1) {
                continue;
            }

            $processed++;

            try {
                [$status, $error] = $this->_sendStep($row);
            } catch (\Throwable $e) {
                Craft::error("Dispatch drip step {$row['id']} failed: " . $e->getMessage(), 'dispatch');
                [$status, $error] = ['failed', $e->getMessage()];
            }

            Db::update(self::SENDS, [
                'status' => $status,
                'error' => $error,
                'sentAt' => $status === 'sent' ? Scheduler::dbNow() : null,
            ], ['id' => $row['id']], [], false);

            $this->_completeIfFinished((int)$row['enrolmentId']);
        }

        return $processed;
    }

    /**
     * Pending and sent counts for the sequence screens.
     *
     * @return array{active: int, completed: int, stopped: int, pending: int, sent: int, failed: int, steps: array<int, array{sent: int, pending: int}>}
     */
    public function getStats(int $sequenceId): array
    {
        $enrolments = (new Query())->select(['status', 'n' => 'COUNT(*)'])->from(self::ENROLMENTS)
            ->where(['sequenceId' => $sequenceId])->groupBy(['status'])->pairs();

        $sends = (new Query())
            ->select(['s.stepId', 's.status', 'n' => 'COUNT(*)'])
            ->from(['s' => self::SENDS])
            ->innerJoin(['e' => self::ENROLMENTS], '[[e.id]] = [[s.enrolmentId]]')
            ->where(['e.sequenceId' => $sequenceId])
            ->groupBy(['s.stepId', 's.status'])
            ->all();

        $stats = [
            'active' => (int)($enrolments['active'] ?? 0),
            'completed' => (int)($enrolments['completed'] ?? 0),
            'stopped' => (int)($enrolments['stopped'] ?? 0),
            'pending' => 0, 'sent' => 0, 'failed' => 0,
            'steps' => [],
        ];

        foreach ($sends as $row) {
            $n = (int)$row['n'];
            $step = (int)$row['stepId'];
            $stats['steps'][$step] ??= ['sent' => 0, 'pending' => 0];

            if ($row['status'] === 'sent') {
                $stats['sent'] += $n;
                $stats['steps'][$step]['sent'] += $n;
            } elseif ($row['status'] === 'pending') {
                $stats['pending'] += $n;
                $stats['steps'][$step]['pending'] += $n;
            } elseif ($row['status'] === 'failed') {
                $stats['failed'] += $n;
            }
        }

        return $stats;
    }

    /**
     * Sends one claimed step, after checking the subscriber may still be mailed.
     *
     * @param array<string, mixed> $row
     * @return array{0: string, 1: string|null} [status, error]
     */
    private function _sendStep(array $row): array
    {
        $enrolmentId = (int)$row['enrolmentId'];
        $subscriberId = (int)$row['subscriberId'];

        if ($row['enrolmentStatus'] !== 'active') {
            return ['cancelled', 'Enrolment is no longer active.'];
        }

        $subscriber = Subscriber::find()->id($subscriberId)->status(null)->one();

        if (!$subscriber || $subscriber->status !== 'active') {
            $reason = $subscriber ? $subscriber->status : 'deleted';
            $this->stopForSubscriber($subscriberId, $reason);

            return ['cancelled', "Subscriber is {$reason}."];
        }

        if ($row['triggerType'] === 'list' && $row['triggerListId']
            && !SubscriptionRecord::find()->where(['subscriberId' => $subscriberId, 'mailingListId' => (int)$row['triggerListId']])->exists()) {
            $this->stopForSubscriber($subscriberId, 'left the list', (int)$row['triggerListId']);

            return ['cancelled', 'Subscriber left the list.'];
        }

        $campaign = Campaign::find()->id((int)$row['campaignId'])->status(null)->one();

        if (!$campaign) {
            return ['failed', 'The step’s campaign no longer exists.'];
        }

        if (SendLogRecord::find()->where(['campaignId' => $campaign->id, 'subscriberId' => $subscriberId])->exists()) {
            return ['skipped', 'Already received this campaign.'];
        }

        $ok = Plugin::getInstance()->sender->sendToSubscriber($campaign, $subscriber);

        Db::update('{{%dispatch_campaigns}}', [
            'totalRecipients' => new Expression('[[totalRecipients]] + 1'),
            $ok ? 'totalSent' : 'totalFailed' => new Expression($ok ? '[[totalSent]] + 1' : '[[totalFailed]] + 1'),
        ], ['id' => $campaign->id], [], false);

        if ($ok) {
            return ['sent', null];
        }

        $error = SendLogRecord::find()->select('errorMessage')
            ->where(['campaignId' => $campaign->id, 'subscriberId' => $subscriberId])->scalar();

        return ['failed', $error ?: 'Send failed.'];
    }

    private function _completeIfFinished(int $enrolmentId): void
    {
        $open = (new Query())->from(self::SENDS)
            ->where(['enrolmentId' => $enrolmentId, 'status' => ['pending', 'sending']])
            ->exists();

        if (!$open) {
            Db::update(self::ENROLMENTS, ['status' => 'completed'], ['id' => $enrolmentId, 'status' => 'active']);
        }
    }

    /** @return Sequence[] */
    private function _enabledSequences(array $where): array
    {
        $rows = $this->_query()->where(['enabled' => true])->andWhere($where)->all();

        return array_map(fn(array $row) => $this->_model($row), $rows);
    }

    private function _resolveSubscriber(Subscriber|int|string $subscriber): ?Subscriber
    {
        if ($subscriber instanceof Subscriber) {
            return $subscriber;
        }

        $query = Subscriber::find()->status(null);

        return is_int($subscriber)
            ? $query->id($subscriber)->one()
            // Exact match: Db::parseParam would read `*` in an address as a wildcard.
            : $query->andWhere(['dispatch_subscribers.email' => trim($subscriber)])->one();
    }

    private function _query(): Query
    {
        return (new Query())
            ->select(['id', 'name', 'handle', 'triggerType', 'triggerListId', 'triggerEvent', 'enabled', 'uid'])
            ->from(self::SEQUENCES);
    }

    private function _model(array $row): Sequence
    {
        $sequence = new Sequence($row);
        $sequence->enabled = (bool)$row['enabled'];

        return $sequence;
    }
}
