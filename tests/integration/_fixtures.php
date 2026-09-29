<?php
/**
 * Shared fixtures for Dispatch's integration checks (NOT Codeception — never run `codecept` in
 * the shared harness: its dbSetup wipes the database).
 *
 * Everything is named `DP check …` / `dpcheck…` and removed by sweep().
 */

use justinholtweb\dispatch\elements\Campaign;
use justinholtweb\dispatch\elements\MailingList;
use justinholtweb\dispatch\elements\Subscriber;
use justinholtweb\dispatch\Plugin;
use justinholtweb\dispatch\records\SendLogRecord;
use justinholtweb\dispatch\records\SubscriptionRecord;

const DP_PREFIX = 'DP check';

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";

            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

function dp(): Plugin
{
    return Plugin::getInstance();
}

function sweep(): void
{
    $elements = Craft::$app->getElements();

    foreach (Campaign::find()->title(DP_PREFIX . '*')->status(null)->trashed(null)->all() as $c) {
        SendLogRecord::deleteAll(['campaignId' => $c->id]);
        $elements->deleteElement($c, true);
    }

    foreach (Subscriber::find()->email('dpcheck*')->status(null)->trashed(null)->all() as $s) {
        SubscriptionRecord::deleteAll(['subscriberId' => $s->id]);
        $elements->deleteElement($s, true);
    }

    foreach (MailingList::find()->title(DP_PREFIX . '*')->status(null)->trashed(null)->all() as $l) {
        $elements->deleteElement($l, true);
    }
}

function makeList(string $suffix): MailingList
{
    $list = new MailingList();
    $list->title = DP_PREFIX . ' ' . $suffix;
    $list->name = $list->title;
    $list->handle = 'dpcheck' . preg_replace('/[^a-z0-9]/i', '', $suffix) . random_int(100, 999);

    if (!Craft::$app->getElements()->saveElement($list)) {
        throw new RuntimeException('List did not save: ' . json_encode($list->getErrors()));
    }

    return $list;
}

function makeSubscriber(string $suffix, array $attrs = []): Subscriber
{
    $s = new Subscriber();
    $s->email = 'dpcheck' . $suffix . random_int(1000, 9999) . '@example.test';

    foreach ($attrs as $k => $v) {
        $s->$k = $v;
    }

    if (!Craft::$app->getElements()->saveElement($s)) {
        throw new RuntimeException('Subscriber did not save: ' . json_encode($s->getErrors()));
    }

    return $s;
}

function makeCampaign(string $suffix, ?MailingList $list, string $body = '<p>Hello {{ subscriber.firstName }}</p>'): Campaign
{
    $c = new Campaign();
    $c->title = DP_PREFIX . ' ' . $suffix;
    $c->subject = 'Hi {{ subscriber.firstName }}';
    $c->body = $body;
    $c->mailingListId = $list?->id;

    if (!Craft::$app->getElements()->saveElement($c)) {
        throw new RuntimeException('Campaign did not save: ' . json_encode($c->getErrors()));
    }

    return $c;
}

/** A send-log row, as if the campaign had gone to this subscriber with this Message-ID. */
function logSend(Campaign $c, Subscriber $s, string $messageId): void
{
    $r = new SendLogRecord();
    $r->campaignId = $c->id;
    $r->subscriberId = $s->id;
    $r->status = 'sent';
    $r->sentAt = craft\helpers\Db::prepareDateForDb(new DateTime());
    $r->messageId = $messageId;
    $r->save(false);
}

function logStatus(Campaign $c, Subscriber $s): ?string
{
    return SendLogRecord::find()->select('status')->where(['campaignId' => $c->id, 'subscriberId' => $s->id])->scalar() ?: null;
}

function subscriberStatus(Subscriber $s): ?string
{
    return Subscriber::find()->id($s->id)->status(null)->one()?->status;
}

function isOnList(Subscriber $s, MailingList $l): bool
{
    return SubscriptionRecord::find()->where(['subscriberId' => $s->id, 'mailingListId' => $l->id])->exists();
}
