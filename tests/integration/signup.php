<?php
/**
 * Dispatch 5.3 sign-up forms with double opt-in — the service, then the real endpoints over HTTP.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-dispatch/tests/integration/signup.php
 *
 * Settings are changed in memory only for the service half; the HTTP half runs on the harness's
 * saved settings (double opt-in on, the default). Fixtures are `DP check …` / `dpcheck…` and swept.
 * Mail sent in this process is captured and never delivered.
 */

$root = getcwd();
require $root . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
require __DIR__ . '/_fixtures.php';

use craft\helpers\Db;
use justinholtweb\dispatch\controllers\SignupController;
use justinholtweb\dispatch\elements\Subscriber;
use justinholtweb\dispatch\events\SignupEvent;
use justinholtweb\dispatch\helpers\Optin;
use justinholtweb\dispatch\records\PendingSignupRecord;
use justinholtweb\dispatch\services\Signups;
use justinholtweb\dispatch\services\Subscribers;
use yii\base\Event;
use yii\mail\BaseMailer;
use yii\mail\MailEvent;

$settings = dp()->getSettings();
$original = $settings->toArray();
$signups = dp()->signups;

/** Mail this process tried to send. */
$mail = [];
Event::on(BaseMailer::class, BaseMailer::EVENT_BEFORE_SEND, function(MailEvent $e) use (&$mail) {
    $mail[] = $e->message;
    $e->isValid = false;
});

function sweepSignups(): void
{
    Db::delete('{{%dispatch_pendingsignups}}', ['like', 'email', 'dpcheck%', false]);
}

function pending(string $email): ?PendingSignupRecord
{
    return PendingSignupRecord::find()->where(['email' => mb_strtolower($email)])->one();
}

function freshEmail(string $suffix): string
{
    return 'dpcheck' . $suffix . random_int(10000, 99999) . '@example.test';
}

function subscriberByEmail(string $email): ?Subscriber
{
    return Subscriber::find()->status(null)->andWhere(['dispatch_subscribers.email' => $email])->one();
}

sweep();
sweepSignups();

$list = makeList('signup');
$other = makeList('signup other');
$secret = makeList('signup secret');

// -----------------------------------------------------------------------------------------------
section('Form config is signed');

check('signConfig/readConfig round-trips the lists, wording, source and choice flag', function() use ($signups, $list) {
    $c = $signups->readConfig($signups->signConfig(['lists' => [$list->handle], 'consentText' => "I agree\r\n", 'source' => 'footer', 'chooseLists' => true]));

    return $c === ['lists' => [$list->handle], 'consentText' => 'I agree', 'source' => 'footer', 'chooseLists' => true] ?: json_encode($c);
});

check('a tampered value is refused', function() use ($signups, $list, $secret) {
    $signed = $signups->signConfig(['lists' => [$list->handle]]);
    $tampered = str_replace($list->handle, $secret->handle, $signed);

    return $signups->readConfig($tampered) === null && $signups->readConfig('{"lists":["' . $secret->handle . '"]}') === null && $signups->readConfig(['x']) === null;
});

check('list handles are matched exactly (no `*` wildcard); unknown lists are left out', function() use ($signups, $list) {
    return $signups->resolveLists(['*']) === []
        && $signups->resolveLists(['dpcheck*']) === []
        && $signups->resolveLists(['dpchecknosuchlist']) === []
        && array_map(fn($l) => $l->id, $signups->resolveLists([$list->handle, (string)$list->id])) === [$list->id];
});

// -----------------------------------------------------------------------------------------------
section('Double opt-in');

$settings->doubleOptIn = true;
$email = freshEmail('doi');

check('a new address gets a pending sign-up and a confirmation email — not a subscription', function() use ($signups, $email, $list, &$mail) {
    $mail = [];
    $outcome = $signups->start($email, [$list->id], ['firstName' => 'Dee', 'consentText' => 'I agree to [the policy](/privacy).', 'source' => 'footer']);
    $row = pending($email);

    return $outcome === Signups::OUTCOME_PENDING
        && $row !== null
        && subscriberByEmail($email) === null
        && count($mail) === 1
        ?: [$outcome, $row?->id, count($mail)];
});

$token = $signups->lastToken;

check('only the SHA-256 of the 43-character token is stored', function() use ($email, $token) {
    $row = pending($email);

    return Optin::isTokenShaped($token) && $row->tokenHash === hash('sha256', $token) && !str_contains(json_encode($row->toArray()), $token);
});

check('the email links to /dispatch/confirm?code=… (never `token=`), escapes the HTML part', function() use (&$mail, $token, $list) {
    $m = $mail[0] ?? null;
    if (!$m) {
        return 'no mail';
    }
    $sym = $m->getSymfonyEmail();
    $text = (string)$sym->getTextBody();
    $html = (string)$sym->getHtmlBody();

    return str_contains($text, 'dispatch/confirm?code=' . $token)
        && !str_contains($text, 'token=')
        && str_contains($text, $list->title)
        && str_contains($html, '<a href=')
        ?: substr($text, 0, 300);
});

check('the consent evidence is kept with the sign-up (wording + its version, source, lists)', function() use ($email, $list) {
    $c = pending($email)->consent;
    $c = is_string($c) ? json_decode($c, true) : $c;

    return ($c['text'] ?? null) === 'I agree to [the policy](/privacy).'
        && ($c['textVersion'] ?? null) === hash('sha256', 'I agree to [the policy](/privacy).')
        && ($c['source'] ?? null) === 'footer'
        && ($c['lists'] ?? null) === [$list->handle]
        ?: json_encode($c);
});

check('signing up again moments later sends nothing (and keeps the first link working)', function() use ($signups, $email, $list, &$mail, $token) {
    $mail = [];

    return $signups->start($email, [$list->id]) === Signups::OUTCOME_THROTTLED && $mail === [] && $signups->findPending($token) !== null;
});

check('lookups: malformed, wildcard and unknown tokens find nothing', function() use ($signups) {
    return $signups->findPending('*') === null
        && $signups->findPending('') === null
        && $signups->findPending(null) === null
        && $signups->findPending(['x']) === null
        && $signups->findPending(str_repeat('A', 43)) === null
        && $signups->confirm('%') === null;
});

$subscribedEvents = 0;
Event::on(Subscribers::class, Subscribers::EVENT_AFTER_SUBSCRIBE, function() use (&$subscribedEvents) {
    $subscribedEvents++;
});

check('confirming makes an active subscriber on the list, with the consent recorded', function() use ($signups, $token, $email, $list, &$subscribedEvents) {
    $subscribedEvents = 0;
    $s = $signups->confirm($token);
    $fresh = subscriberByEmail($email);

    return $s !== null
        && $fresh?->status === 'active'
        && $fresh->firstName === 'Dee'
        && isOnList($fresh, $list)
        && $fresh->consentedAt !== null
        && ($fresh->consent['method'] ?? null) === 'double-opt-in'
        && isset($fresh->consent['confirmedAt'])
        && pending($email) === null
        && $subscribedEvents === 1
        ?: json_encode([$fresh?->status, $fresh?->consent, $subscribedEvents]);
});

check('a token works once', fn() => $signups->confirm($token) === null);

check('consentedAt is stored in UTC and read back as UTC', function() use ($email) {
    $d = subscriberByEmail($email)->getConsentedAtDate();

    return $d !== null && abs($d->getTimestamp() - time()) < 120 ?: $d?->format(DATE_ATOM);
});

check('an active subscriber already on the list gets no email', function() use ($signups, $email, $list, &$mail) {
    $mail = [];

    return $signups->start($email, [$list->id]) === Signups::OUTCOME_ALREADY && $mail === [];
});

check('an active subscriber asking for another list must confirm that too', function() use ($signups, $email, $other, &$mail) {
    $mail = [];
    $outcome = $signups->start($email, [$other->id]);
    $s = subscriberByEmail($email);

    return $outcome === Signups::OUTCOME_PENDING && count($mail) === 1 && !isOnList($s, $other) ?: $outcome;
});

check('bounced and complained addresses are not mailed and not changed', function() use ($signups, $list, &$mail) {
    $mail = [];
    $b = makeSubscriber('bounced', ['status' => 'bounced']);
    $c = makeSubscriber('complained', ['status' => 'complained']);

    return $signups->start($b->email, [$list->id]) === Signups::OUTCOME_SUPPRESSED
        && $signups->start($c->email, [$list->id]) === Signups::OUTCOME_SUPPRESSED
        && $mail === []
        && pending($b->email) === null
        && subscriberStatus($b) === 'bounced';
});

check('someone who unsubscribed can come back — by confirming', function() use ($signups, $list) {
    $u = makeSubscriber('unsub', ['status' => 'unsubscribed', 'unsubscribedAt' => Db::prepareDateForDb(new DateTime())]);
    $outcome = $signups->start($u->email, [$list->id]);
    $still = subscriberStatus($u);
    $s = $signups->confirm($signups->lastToken);
    $fresh = Subscriber::find()->id($u->id)->status(null)->one();

    return $outcome === Signups::OUTCOME_PENDING && $still === 'unsubscribed' && $s?->id === $u->id && $fresh->status === 'active' && $fresh->unsubscribedAt === null && isOnList($fresh, $list)
        ?: [$outcome, $still, $fresh?->status];
});

check('an expired link finds nothing, confirms nothing, and garbage collection removes it', function() use ($signups, $list) {
    $e = freshEmail('expired');
    $signups->start($e, [$list->id]);
    $t = $signups->lastToken;
    Db::update('{{%dispatch_pendingsignups}}', ['expiresAt' => Db::prepareDateForDb(new DateTime('-1 minute'))], ['email' => $e]);
    $found = $signups->findPending($t);
    $confirmed = $signups->confirm($t);
    dp()->collectGarbage();

    return $found === null && $confirmed === null && pending($e) === null && subscriberByEmail($e) === null;
});

check('a fresh link replaces the old one (after the resend window) and adds the new lists', function() use ($signups, $list, $other, &$mail) {
    $e = freshEmail('again');
    $signups->start($e, [$list->id]);
    $first = $signups->lastToken;
    Db::update('{{%dispatch_pendingsignups}}', ['dateUpdated' => Db::prepareDateForDb(new DateTime('-10 minutes'))], ['email' => $e]);
    $outcome = $signups->start($e, [$other->id]);
    $second = $signups->lastToken;
    $ids = pending($e)->listIds;
    $ids = is_string($ids) ? json_decode($ids, true) : $ids;

    return $outcome === Signups::OUTCOME_PENDING && $signups->findPending($first) === null && $signups->findPending($second) !== null && $ids == [$list->id, $other->id]
        ?: json_encode([$outcome, $ids]);
});

check('at most ' . Signups::MAX_CONFIRMATIONS_PER_DAY . ' confirmation emails a day to one address', function() use ($signups, $list, &$mail) {
    $e = freshEmail('bomb');
    $mail = [];
    $outcomes = [];
    for ($i = 0; $i < Signups::MAX_CONFIRMATIONS_PER_DAY + 2; $i++) {
        $outcomes[] = $signups->start($e, [$list->id]);
        Db::update('{{%dispatch_pendingsignups}}', ['dateUpdated' => Db::prepareDateForDb(new DateTime('-10 minutes'))], ['email' => $e]);
    }

    return count($mail) === Signups::MAX_CONFIRMATIONS_PER_DAY && end($outcomes) === Signups::OUTCOME_THROTTLED ?: json_encode($outcomes);
});

check('an EVENT_BEFORE_SIGNUP handler can drop a sign-up', function() use ($signups, $list, &$mail) {
    $mail = [];
    $h = function(SignupEvent $e) {
        $e->isValid = !str_contains($e->email, 'spam');
    };
    Event::on(Signups::class, Signups::EVENT_BEFORE_SIGNUP, $h);
    $e = freshEmail('spam');
    $outcome = $signups->start($e, [$list->id]);
    Event::off(Signups::class, Signups::EVENT_BEFORE_SIGNUP, $h);

    return $outcome === Signups::OUTCOME_VETOED && pending($e) === null && $mail === [];
});

check('EVENT_AFTER_CONFIRM fires with the subscriber', function() use ($signups, $list) {
    $seen = null;
    $h = function(SignupEvent $e) use (&$seen) {
        $seen = $e->subscriber?->email;
    };
    Event::on(Signups::class, Signups::EVENT_AFTER_CONFIRM, $h);
    $e = freshEmail('event');
    $signups->start($e, [$list->id]);
    $signups->confirm($signups->lastToken);
    Event::off(Signups::class, Signups::EVENT_AFTER_CONFIRM, $h);

    return $seen === $e ?: $seen;
});

check('pendingCount counts unexpired sign-ups', fn() => $signups->pendingCount() >= 1);

// -----------------------------------------------------------------------------------------------
section('Single opt-in (double opt-in off)');

$settings->doubleOptIn = false;

check('a new address is subscribed at once, consent marked single opt-in, no email', function() use ($signups, $list, &$mail) {
    $mail = [];
    $e = freshEmail('single');
    $outcome = $signups->start($e, [$list->id], ['consentText' => 'Yes please']);
    $s = subscriberByEmail($e);

    return $outcome === Signups::OUTCOME_SUBSCRIBED && $s?->status === 'active' && isOnList($s, $list) && ($s->consent['method'] ?? null) === 'single-opt-in' && $mail === [] && pending($e) === null
        ?: [$outcome, $s?->consent];
});

check('…but an unsubscribed address is not re-subscribed without a confirmation', function() use ($signups, $list) {
    $u = makeSubscriber('single-unsub', ['status' => 'unsubscribed']);

    return $signups->start($u->email, [$list->id]) === Signups::OUTCOME_SUPPRESSED && subscriberStatus($u) === 'unsubscribed' && !isOnList($u, $list);
});

$settings->doubleOptIn = true;

// -----------------------------------------------------------------------------------------------
section('Settings and element');

check('the confirmation body must contain {link}', function() {
    $s = new justinholtweb\dispatch\models\Settings(['confirmationBody' => 'Click nothing']);
    $ok = new justinholtweb\dispatch\models\Settings();

    return !$s->validate(['confirmationBody']) && $ok->validate(['confirmationBody', 'lockPurpose', 'confirmationExpiryHours']);
});

check('the sign-up settings are admin-editable keys', fn() => array_diff(['doubleOptIn', 'confirmationBody', 'lockPurpose', 'signupsPerMinute'], justinholtweb\dispatch\models\Settings::EDITABLE) === []);

check('a subscriber with metadata and consent JSON loads (json columns decoded)', function() {
    $s = makeSubscriber('json', ['metadata' => ['a' => 1], 'consent' => ['method' => 'single-opt-in']]);
    $fresh = Subscriber::find()->id($s->id)->status(null)->one();

    return $fresh->metadata === ['a' => 1] && $fresh->consent === ['method' => 'single-opt-in'] ?: json_encode([$fresh->metadata, $fresh->consent]);
});

check('consentedAt and consent are reserved field handles', function() {
    $src = file_get_contents(dirname(__DIR__, 2) . '/src/controllers/CampaignsController.php');

    return str_contains($src, "'consentedAt', 'consent']");
});

// -----------------------------------------------------------------------------------------------
section('Lock and Toss (when installed)');

$lockOn = Craft::$app->getPlugins()->isPluginEnabled('lock');

if ($lockOn) {
    $settings->lockPurpose = 'dpcheck-marketing';

    check('a confirmed sign-up is recorded in Lock’s ledger, verified, without the raw IP', function() use ($signups, $list) {
        $e = freshEmail('lock');
        $signups->start($e, [$list->id], ['consentText' => 'I agree']);
        $signups->confirm($signups->lastToken);
        $row = (new craft\db\Query())->from('{{%lock_consents}}')->where(['email' => $e, 'purpose' => 'dpcheck-marketing'])->one();
        $ev = is_string($row['evidence'] ?? null) ? json_decode($row['evidence'], true) : ($row['evidence'] ?? []);

        return ($row['state'] ?? null) === 'granted' && ($ev['verified'] ?? null) === true && ($ev['via'] ?? null) === 'dispatch' && ($ev['text'] ?? null) === 'I agree' && !isset($ev['ip'])
            ?: json_encode($row);
    });

    check('a full unsubscribe is recorded in Lock as a withdrawal; leaving one list is not', function() use ($list, $other) {
        $s = Subscriber::find()->status(null)->andWhere(['like', 'dispatch_subscribers.email', 'dpchecklock%', false])->one();
        dp()->subscribers->subscribe($s->id, $other->id);
        dp()->subscribers->removeFromList($s->id, $other->id);
        $afterLeave = (new craft\db\Query())->from('{{%lock_consents}}')->where(['email' => $s->email, 'state' => 'withdrawn'])->count();
        dp()->subscribers->unsubscribe($s->id);
        $afterUnsub = (new craft\db\Query())->from('{{%lock_consents}}')->where(['email' => $s->email, 'state' => 'withdrawn', 'purpose' => 'dpcheck-marketing'])->count();

        return (int)$afterLeave === 0 && (int)$afterUnsub === 1 ?: [$afterLeave, $afterUnsub];
    });

    check('single opt-in sign-ups never reach Lock (nobody proved the address)', function() use ($signups, $list, $settings) {
        $settings->doubleOptIn = false;
        $e = freshEmail('lock-single');
        $signups->start($e, [$list->id]);
        $settings->doubleOptIn = true;

        return !(new craft\db\Query())->from('{{%lock_consents}}')->where(['email' => $e])->exists();
    });

    check('a blank purpose turns Lock recording off', function() use ($signups, $list, $settings) {
        $settings->lockPurpose = '';
        $e = freshEmail('lock-off');
        $signups->start($e, [$list->id]);
        $signups->confirm($signups->lastToken);
        $settings->lockPurpose = 'dpcheck-marketing';

        return !(new craft\db\Query())->from('{{%lock_consents}}')->where(['email' => $e])->exists();
    });

    Db::delete('{{%lock_consents}}', ['purpose' => 'dpcheck-marketing']);
    Db::delete('{{%lock_consents}}', ['like', 'email', 'dpcheck%', false]);
} else {
    echo "  - Lock is not installed: Lock checks skipped\n";
}

check('the Toss read never breaks a sign-up made outside a web request', function() use ($signups, $list) {
    $e = freshEmail('toss');
    $outcome = $signups->start($e, [$list->id]);
    $c = pending($e)->consent;
    $c = is_string($c) ? json_decode($c, true) : $c;

    return $outcome === Signups::OUTCOME_PENDING && (!isset($c['toss']) || is_array($c['toss']));
});

// Settings back exactly as they were before the HTTP half reads the saved ones.
foreach ($original as $k => $v) {
    $settings->$k = $v;
}

// -----------------------------------------------------------------------------------------------
// The endpoints, over HTTP. Mail sent by the web process goes to the harness's own mail catcher.

$host = parse_url(Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), PHP_URL_HOST) ?: 'localhost';
$base = getenv('DP_CP_BASE') ?: 'http://' . $host;
$jar = tempnam(sys_get_temp_dir(), 'dps');

function req(string $method, string $path, array $post = [], array $headers = []): array
{
    global $base, $jar, $host;
    $ch = curl_init($base . $path);
    $respHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'dispatch-signup-checks',
        CURLOPT_RESOLVE => ["$host:443:127.0.0.1", "$host:80:127.0.0.1"],
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADERFUNCTION => function($ch, $line) use (&$respHeaders) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $respHeaders[strtolower(trim($k))] = trim($v);
            }

            return strlen($line);
        },
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string)curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return [$code, $body, $respHeaders];
}

function csrfToken(): string
{
    [, $body] = req('GET', '/actions/users/session-info', [], ['Accept: application/json']);

    return (string)(json_decode($body, true)['csrfTokenValue'] ?? '');
}

/** The web process counts sign-ups per address per minute; start each check with a clean budget. */
function resetRate(): void
{
    $cache = Craft::$app->getCache();
    $minute = intdiv(time(), 60);
    foreach (['signup', 'confirm'] as $bucket) {
        foreach ([$minute - 1, $minute, $minute + 1] as $m) {
            foreach (['127.0.0.1', '::1', 'unknown', '172.17.0.1'] as $ip) {
                $cache->delete(sprintf('dispatch:rate:%s:%s:%d', $bucket, sha1(justinholtweb\dispatch\helpers\RateLimit::key($ip)), $m));
            }
            $cache->delete(sprintf('dispatch:rate:%s:*:%d', $bucket, $m));
        }
    }
}

$saved = (bool)(json_decode((string)shell_exec('php ' . escapeshellarg(__DIR__ . '/_pc.php') . ' get'), true)['doubleOptIn'] ?? true);
$json = ['Accept: application/json'];
$csrf = csrfToken();
$signed = $signups->signConfig(['lists' => [$list->handle], 'source' => 'http']);

section('Endpoint: dispatch/signup/subscribe');

check('the harness runs with double opt-in on (the default)', fn() => $saved === true);

check('without a CSRF token the post is refused', function() use ($signed) {
    resetRate();
    [$code] = req('POST', '/actions/dispatch/signup/subscribe', ['signup' => $signed, 'email' => freshEmail('nocsrf')], ['Accept: application/json']);

    return $code === 400 ?: $code;
});

check('a GET is refused', function() {
    [$code] = req('GET', '/actions/dispatch/signup/subscribe?email=x@example.test');

    return in_array($code, [400, 405], true) ?: $code;
});

$answers = [];

check('a new address: 200, "check your inbox", a pending row and no subscriber', function() use ($signed, $csrf, $json, &$answers) {
    resetRate();
    $e = freshEmail('web');
    [$code, $body] = req('POST', '/actions/dispatch/signup/subscribe', ['CRAFT_CSRF_TOKEN' => $csrf, 'signup' => $signed, 'email' => $e], $json);
    $answers['new'] = [$code, $body];
    $d = json_decode($body, true);

    return $code === 200 && ($d['success'] ?? null) === true && ($d['confirm'] ?? null) === true && pending($e) !== null && subscriberByEmail($e) === null ?: [$code, $body];
});

check('known, bounced and just-signed-up addresses get the byte-identical answer', function() use ($signed, $csrf, $json, &$answers, $list) {
    resetRate();
    $active = makeSubscriber('web-active');
    dp()->subscribers->subscribe($active->id, $list->id);
    $bounced = makeSubscriber('web-bounced', ['status' => 'bounced']);
    $bodies = [$answers['new'][1] ?? 'none'];

    foreach ([$active->email, $bounced->email] as $e) {
        [, $body] = req('POST', '/actions/dispatch/signup/subscribe', ['CRAFT_CSRF_TOKEN' => $csrf, 'signup' => $signed, 'email' => $e], $json);
        $bodies[] = $body;
    }

    return count(array_unique($bodies)) === 1 && pending($bounced->email) === null ?: json_encode($bodies);
});

check('a tampered form config is refused and stores nothing', function() use ($signed, $csrf, $json, $list, $secret) {
    resetRate();
    $e = freshEmail('tamper');
    [$code, $body] = req('POST', '/actions/dispatch/signup/subscribe', ['CRAFT_CSRF_TOKEN' => $csrf, 'signup' => str_replace($list->handle, $secret->handle, $signed), 'email' => $e], $json);

    return $code === 400 && isset(json_decode($body, true)['errors']['form']) && pending($e) === null ?: [$code, $body];
});

check('a list the form doesn’t offer can’t be chosen', function() use ($signups, $csrf, $json, $list, $other, $secret) {
    resetRate();
    $choose = $signups->signConfig(['lists' => [$list->handle, $other->handle], 'chooseLists' => true]);
    $e = freshEmail('choose');
    req('POST', '/actions/dispatch/signup/subscribe', ['CRAFT_CSRF_TOKEN' => $csrf, 'signup' => $choose, 'email' => $e, 'lists' => ['', $other->handle, $secret->handle, (string)$secret->id]], $json);
    $ids = pending($e)?->listIds;
    $ids = is_string($ids) ? json_decode($ids, true) : $ids;

    return $ids == [$other->id] ?: json_encode($ids);
});

check('choosing no list is an error', function() use ($signups, $csrf, $json, $list, $other) {
    resetRate();
    $choose = $signups->signConfig(['lists' => [$list->handle, $other->handle], 'chooseLists' => true]);
    [$code, $body] = req('POST', '/actions/dispatch/signup/subscribe', ['CRAFT_CSRF_TOKEN' => $csrf, 'signup' => $choose, 'email' => freshEmail('none'), 'lists' => ['']], $json);

    return $code === 400 && isset(json_decode($body, true)['errors']['lists']) ?: [$code, $body];
});

check('a consent box the form shows must be ticked — enforced by the server', function() use ($signups, $csrf, $json, $list) {
    resetRate();
    $withBox = $signups->signConfig(['lists' => [$list->handle], 'consentText' => 'I agree']);
    $e = freshEmail('consent');
    [$code, $body] = req('POST', '/actions/dispatch/signup/subscribe', ['CRAFT_CSRF_TOKEN' => $csrf, 'signup' => $withBox, 'email' => $e], $json);
    $refused = $code === 400 && isset(json_decode($body, true)['errors']['consent']) && pending($e) === null;
    [$code2] = req('POST', '/actions/dispatch/signup/subscribe', ['CRAFT_CSRF_TOKEN' => $csrf, 'signup' => $withBox, 'email' => $e, 'consent' => '1'], $json);

    return $refused && $code2 === 200 && pending($e) !== null ?: [$code, $body, $code2];
});

check('a bad email address is an error', function() use ($signed, $csrf, $json) {
    resetRate();
    [$code, $body] = req('POST', '/actions/dispatch/signup/subscribe', ['CRAFT_CSRF_TOKEN' => $csrf, 'signup' => $signed, 'email' => 'not an email'], $json);

    return $code === 400 && isset(json_decode($body, true)['errors']['email']) ?: [$code, $body];
});

check('a filled-in honeypot is told it worked, and nothing is stored', function() use ($signed, $csrf, $json, &$answers) {
    resetRate();
    $e = freshEmail('bot');
    [$code, $body] = req('POST', '/actions/dispatch/signup/subscribe', ['CRAFT_CSRF_TOKEN' => $csrf, 'signup' => $signed, 'email' => $e, SignupController::HONEYPOT => 'gotcha'], $json);

    return $code === 200 && $body === ($answers['new'][1] ?? '') && pending($e) === null ?: [$code, $body];
});

check('more than signupsPerMinute sign-ups from one address in a minute get 429', function() use ($signed, $csrf, $json, $settings) {
    resetRate();
    $codes = [];
    for ($i = 0; $i < $settings->signupsPerMinute + 1; $i++) {
        [$codes[]] = req('POST', '/actions/dispatch/signup/subscribe', ['CRAFT_CSRF_TOKEN' => $csrf, 'signup' => $signed, 'email' => 'bad'], $json);
    }
    resetRate();

    return end($codes) === 429 && $codes[0] === 400 ?: json_encode($codes);
});

section('Endpoint: dispatch/confirm');

$e = freshEmail('webconfirm');
$signups->start($e, [$list->id]);
$webToken = $signups->lastToken;

check('opening the link shows a Confirm button and changes nothing (mail scanners)', function() use ($webToken, $e) {
    resetRate();
    [$code, $body, $h] = req('GET', '/dispatch/confirm?code=' . $webToken);

    return $code === 200
        && str_contains($body, 'name="code" value="' . $webToken . '"')
        && str_contains($body, '<meta name="referrer" content="no-referrer">')
        && str_contains($h['cache-control'] ?? '', 'no-store')
        && ($h['referrer-policy'] ?? '') === 'no-referrer'
        && str_contains($h['x-robots-tag'] ?? '', 'noindex')
        && subscriberByEmail($e) === null
        && pending($e) !== null
        ?: json_encode([$code, str_contains($body, 'name="code" value="' . $webToken . '"'), str_contains($body, '<meta name="referrer" content="no-referrer">'), $h, subscriberByEmail($e)?->id, pending($e)?->id, substr($body, strpos($body, '<form') ?: 0, 600)]);
});

check('a bad code is a 404 with no code in the page', function() {
    [$code, $body] = req('GET', '/dispatch/confirm?code=' . str_repeat('B', 43));
    [$code2] = req('GET', '/dispatch/confirm?code=*');

    return $code === 404 && $code2 === 404 && !str_contains($body, 'name="code"') ?: [$code, $code2];
});

check('the Confirm POST needs the CSRF token', function() use ($webToken, $e) {
    [$code] = req('POST', '/dispatch/confirm', ['action' => 'dispatch/signup/confirm', 'code' => $webToken]);

    return $code === 400 && subscriberByEmail($e) === null ?: $code;
});

check('pressing Confirm subscribes them', function() use ($webToken, $e, $list) {
    $csrf = csrfToken();
    [$code, $body] = req('POST', '/dispatch/confirm', ['CRAFT_CSRF_TOKEN' => $csrf, 'action' => 'dispatch/signup/confirm', 'code' => $webToken]);
    $s = subscriberByEmail($e);

    return $code === 200 && str_contains($body, 'subscribed') && $s?->status === 'active' && isOnList($s, $list) ?: [$code, substr(strip_tags($body), 0, 200)];
});

check('pressing it again is a 404', function() use ($webToken) {
    $csrf = csrfToken();
    [$code] = req('POST', '/dispatch/confirm', ['CRAFT_CSRF_TOKEN' => $csrf, 'action' => 'dispatch/signup/confirm', 'code' => $webToken]);

    return $code === 404 ?: $code;
});

check('the confirm page is rate-limited too', function() {
    resetRate();
    $codes = [];
    for ($i = 0; $i < SignupController::CONFIRM_PER_MINUTE + 1; $i++) {
        [$codes[]] = req('GET', '/dispatch/confirm?code=x');
    }
    resetRate();

    return end($codes) === 429 ?: json_encode(array_count_values($codes));
});

section('craft.dispatch.signupForm() in a site template');

$tpl = Craft::getAlias('@templates') . '/dispatch-signup-test.twig';
file_put_contents($tpl, <<<'TWIG'
<!DOCTYPE html><html><body>
{{ craft.dispatch.signupForm(craft.app.request.getQueryParam('list') ?? 'nope', {
    consentText: 'I agree to the [privacy policy](/privacy) <b>now</b>',
    askName: true,
    source: 'test page',
    formId: 'dpform',
}) }}
</body></html>
TWIG);

check('the form renders with CSRF, the signed config, the honeypot and the escaped, linked wording', function() use ($list) {
    resetRate();
    [$code, $body] = req('GET', '/dispatch-signup-test?list=' . $list->handle);

    return $code === 200
        && str_contains($body, 'name="CRAFT_CSRF_TOKEN"')
        && str_contains($body, 'name="signup"')
        && str_contains($body, 'name="' . SignupController::HONEYPOT . '"')
        && str_contains($body, '<a href="/privacy" target="_blank" rel="noopener">privacy policy</a>')
        && str_contains($body, '&lt;b&gt;now&lt;/b&gt;')
        && str_contains($body, 'name="firstName"')
        ?: [$code, substr($body, 0, 600)];
});

check('a failed post re-renders the page with the error and the typed values', function() use ($list) {
    resetRate();
    [, $page] = req('GET', '/dispatch-signup-test?list=' . $list->handle);
    preg_match('/name="CRAFT_CSRF_TOKEN" value="([^"]+)"/', $page, $c);
    preg_match('/name="signup" value="([^"]+)"/', $page, $s);
    [$code, $body] = req('POST', '/dispatch-signup-test?list=' . $list->handle, [
        'CRAFT_CSRF_TOKEN' => html_entity_decode($c[1] ?? ''), 'action' => 'dispatch/signup/subscribe', 'formId' => 'dpform',
        'signup' => html_entity_decode($s[1] ?? ''), 'email' => 'nope', 'firstName' => 'Fay<script>',
    ]);

    return $code === 200 && str_contains($body, 'Please enter a valid email address.') && str_contains($body, 'value="Fay&lt;script&gt;"') && str_contains($body, 'Please tick the box')
        ?: [$code, substr(strip_tags($body), 0, 400)];
});

check('a good post redirects back and the form shows the success message once', function() use ($list) {
    resetRate();
    [, $page] = req('GET', '/dispatch-signup-test?list=' . $list->handle);
    preg_match('/name="CRAFT_CSRF_TOKEN" value="([^"]+)"/', $page, $c);
    preg_match('/name="signup" value="([^"]+)"/', $page, $s);
    $e = freshEmail('page');
    [$code] = req('POST', '/dispatch-signup-test?list=' . $list->handle, [
        'CRAFT_CSRF_TOKEN' => html_entity_decode($c[1] ?? ''), 'action' => 'dispatch/signup/subscribe', 'formId' => 'dpform',
        'signup' => html_entity_decode($s[1] ?? ''), 'email' => $e, 'consent' => '1',
    ]);
    [, $after] = req('GET', '/dispatch-signup-test?list=' . $list->handle);
    [, $again] = req('GET', '/dispatch-signup-test?list=' . $list->handle);
    $row = pending($e);
    $consent = is_string($row?->consent) ? json_decode($row->consent, true) : $row?->consent;

    return $code === 302 && str_contains($after, 'check your inbox') && !str_contains($again, 'check your inbox')
        && ($consent['source'] ?? null) === 'test page' && isset($consent['ipHash']) && strlen($consent['ipHash']) === 64 && !str_contains(json_encode($consent), '127.0.0.1')
        ?: [$code, substr(strip_tags($after), 0, 300), $consent];
});

section('CP');

check('the Sign-up Forms settings page renders for an admin', function() {
    resetRate();
    $user = getenv('DP_CP_USER') ?: 'admin';
    [, $html] = req('GET', '/admin/login');
    $csrf = preg_match('/name="CRAFT_CSRF_TOKEN" value="([^"]+)"/', $html, $m) || preg_match('/"csrfTokenValue":"([^"]+)"/', $html, $m) ? stripcslashes($m[1]) : csrfToken();
    [$code, $body] = req('POST', '/admin/actions/users/login', ['CRAFT_CSRF_TOKEN' => $csrf, 'loginName' => $user, 'password' => getenv('DP_CP_PASS') ?: 'claudepassword'], ['Accept: application/json']);

    if (!($code === 200 && str_contains($body, '"returnUrl"'))) {
        // The shared harness's password login is unreliable (sibling plugins hook it); Craft's
        // own impersonation link signs in without one — as http.php does.
        exec('php craft users/impersonate ' . escapeshellarg($user) . ' 2>&1', $out);
        if (preg_match('#https?://\S+#', implode("\n", $out), $m)) {
            $url = parse_url($m[0]);
            req('GET', $url['path'] . '?' . ($url['query'] ?? ''));
        }
    }

    [$code, $body] = req('GET', '/admin/dispatch/settings/signups');

    return $code === 200 && str_contains($body, 'settings[doubleOptIn]') && str_contains($body, 'waiting for their confirmation') ?: [$code, substr(strip_tags($body), 0, 300)];
});

// Teardown
@unlink($tpl);
@unlink($jar);
sweepSignups();
sweep();

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
