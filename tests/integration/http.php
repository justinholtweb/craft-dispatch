<?php
/**
 * Dispatch request-level checks: the real links, webhooks and CP actions over HTTP.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-dispatch/tests/integration/http.php
 *
 * Needs Dispatch on its Pro edition (webhooks, API, tracking). Plugin settings are changed through
 * a fresh process and restored exactly afterwards; fixtures are named `DP check …` and swept.
 */

$root = getcwd();
require $root . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
require __DIR__ . '/_fixtures.php';

use justinholtweb\dispatch\helpers\TrackingHelper as T;

$base = getenv('DP_CP_BASE') ?: null;
$user = getenv('DP_CP_USER') ?: 'admin';
$pass = getenv('DP_CP_PASS') ?: 'claudepassword';
$host = parse_url(Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), PHP_URL_HOST) ?: 'localhost';
// The site's own host, resolved to this container.
$base ??= 'http://' . $host;
$jar = tempnam(sys_get_temp_dir(), 'dp');

function http(string $method, string $path, array $post = [], array $headers = []): array
{
    global $base, $jar, $host;
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HTTPHEADER => $headers,
        // Craft's `requireUserAgentAndIpForSession` refuses a login with no User-Agent, and PHP's
        // curl sends none — the login answers “Invalid username or password.”
        CURLOPT_USERAGENT => 'dispatch-http-checks',
        CURLOPT_RESOLVE => ["$host:443:127.0.0.1", "$host:80:127.0.0.1"],

        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => false,
    ]);
    global $lastLocation;
    $lastLocation = null;
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $line) {
        global $lastLocation;
        if (stripos($line, 'Location:') === 0) {
            $lastLocation = trim(substr($line, 9));
        }
        return strlen($line);
    });
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string)curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, $body];
}

function csrf(string $html): ?string
{
    return preg_match('/name="CRAFT_CSRF_TOKEN" value="([^"]+)"/', $html, $m)
        || preg_match('/"csrfTokenValue":"([^"]+)"/', $html, $m) ? stripcslashes($m[1]) : null;
}

/** A POST with a raw body and arbitrary headers, outside the cookie jar. */
function raw(string $path, string $body, array $headers = []): array
{
    global $base, $host;
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'dispatch-http-checks',
        CURLOPT_RESOLVE => ["$host:443:127.0.0.1", "$host:80:127.0.0.1"],
    ]);
    $out = (string)curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return [$code, $out];
}

function pathOf(string $url): string
{
    $p = parse_url($url);

    return ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
}

function pc(string $cmd, ?array $value = null): ?array
{
    $arg = $value !== null ? ' ' . escapeshellarg(json_encode($value)) : '';

    return json_decode((string)shell_exec('php ' . escapeshellarg(__DIR__ . '/_pc.php') . " $cmd$arg"), true);
}

function token(): string
{
    [, $body] = http('GET', '/actions/users/session-info', [], ['Accept: application/json']);

    return (string)(json_decode($body, true)['csrfTokenValue'] ?? '');
}

sweep();
$originalSettings = pc('get');

$list = makeList('web');
$other = makeList('web other');
$sub = makeSubscriber('web', ['firstName' => 'Web']);
dp()->subscribers->subscribe($sub->id, $list->id);
$campaign = makeCampaign('web', $list);
$legacy = static fn(...$p) => hash_hmac('sha256', implode(':', $p), Craft::$app->getConfig()->getGeneral()->securityKey . ':dispatch');

echo "\nUnsubscribe links\n";

check('a pre-5.1 ?token= link opens the unsubscribe page (was 400 "Invalid token")', function() use ($sub, $list, $legacy) {
    [$code] = http('GET', '/dispatch/unsubscribe?' . http_build_query(['sid' => $sub->id, 'lid' => $list->id, 'token' => $legacy($sub->id, $list->id)]));

    return $code === 200 ?: "HTTP $code";
});

check('a 5.1 dtoken link opens it', function() use ($sub, $list) {
    [$code, $html] = http('GET', pathOf(T::unsubscribeUrl($sub->id, $list->id)));

    return $code === 200 && str_contains($html, 'name="dtoken"') ?: "HTTP $code";
});

check('a forged or cross-purpose token is refused', function() use ($sub, $list) {
    [$a] = http('GET', '/dispatch/unsubscribe?' . http_build_query(['sid' => $sub->id, 'lid' => $list->id, 'dtoken' => str_repeat('a', 64)]));
    [$b] = http('GET', '/dispatch/unsubscribe?' . http_build_query(['sid' => $sub->id, 'lid' => $list->id, 'dtoken' => T::sign(T::PURPOSE_OPEN, $sub->id, $list->id)]));

    return $a === 404 && $b === 404 ?: "forged $a, cross $b";
});

check('a Craft preview-shaped token is left alone (still Craft\'s to reject)', function() use ($sub) {
    [$code] = http('GET', '/dispatch/unsubscribe?' . http_build_query(['sid' => $sub->id, 'lid' => 1, 'token' => 'abcdefghijklmnopqrstuvwxyz012345']));

    return $code === 400 ?: "HTTP $code";
});

check('RFC 8058 one-click POST (no CSRF) unsubscribes', function() use ($sub, $list) {
    [$code] = raw(pathOf(T::unsubscribeUrl($sub->id, $list->id)), 'List-Unsubscribe=One-Click', ['Content-Type: application/x-www-form-urlencoded']);
    $gone = !isOnList($sub, $list);
    dp()->subscribers->subscribe($sub->id, $list->id);

    return $code === 200 && $gone ?: "HTTP $code, still subscribed: " . var_export(!$gone, true);
});

echo "\nTracking\n";

check('a tracked link redirects to its URL (legacy and new)', function() use ($campaign, $sub, $legacy) {
    $url = 'https://example.com/landing?a=1';
    [$new] = http('GET', '/dispatch/track/click?' . http_build_query(['cid' => $campaign->id, 'sid' => $sub->id, 'url' => $url, 'dtoken' => T::sign(T::PURPOSE_CLICK, $campaign->id, $sub->id, $url)]));
    global $lastLocation;
    $newLoc = $lastLocation;
    [$old] = http('GET', '/dispatch/track/click?' . http_build_query(['cid' => $campaign->id, 'sid' => $sub->id, 'url' => $url, 'token' => $legacy($campaign->id, $sub->id, $url)]));

    return $new === 302 && $newLoc === $url && $old === 302 && $lastLocation === $url ?: "new $new → $newLoc, legacy $old → $lastLocation";
});

check('a tampered click URL goes home, not to the attacker', function() use ($campaign, $sub) {
    [$code] = http('GET', '/dispatch/track/click?' . http_build_query(['cid' => $campaign->id, 'sid' => $sub->id, 'url' => 'https://evil.test/', 'dtoken' => T::sign(T::PURPOSE_CLICK, $campaign->id, $sub->id, 'https://example.com/')]));
    global $lastLocation;

    return $code === 302 && !str_contains((string)$lastLocation, 'evil.test') ?: "HTTP $code → $lastLocation";
});

check('the open pixel is a GIF and records the open', function() use ($campaign, $sub) {
    [$code, $body] = http('GET', '/dispatch/track/open?' . http_build_query(['cid' => $campaign->id, 'sid' => $sub->id, 'dtoken' => T::sign(T::PURPOSE_OPEN, $campaign->id, $sub->id)]));
    $recorded = (new craft\db\Query())->from('{{%dispatch_tracking}}')->where(['campaignId' => $campaign->id, 'subscriberId' => $sub->id, 'type' => 'open'])->exists();

    return $code === 200 && str_starts_with($body, 'GIF') && $recorded ?: "HTTP $code, recorded " . var_export($recorded, true);
});

echo "\nPreferences\n";

check('the preferences page lists only this subscriber\'s lists', function() use ($sub, $list, $other) {
    [$code, $html] = http('GET', pathOf(T::preferencesUrl($sub->id)));

    return $code === 200 && str_contains($html, (string)$list->title) && !str_contains($html, (string)$other->title) ?: "HTTP $code";
});

check('a legacy (purpose-less) token does not open preferences', function() use ($sub, $legacy) {
    [$code] = http('GET', '/dispatch/preferences?' . http_build_query(['sid' => $sub->id, 'token' => $legacy($sub->id, 0)]));

    return $code === 404 ?: "HTTP $code";
});

check('posting another list ID does not subscribe to it; redirect is the preferences page', function() use ($sub, $list, $other) {
    [, $html] = http('GET', pathOf(T::preferencesUrl($sub->id)));
    [$code] = http('POST', '/actions/dispatch/unsubscribe/update-preferences', [
        'CRAFT_CSRF_TOKEN' => csrf($html), 'sid' => $sub->id, 'dtoken' => T::sign(T::PURPOSE_PREFERENCES, $sub->id),
        'listIds' => [$list->id, $other->id],
    ], ['Referer: https://evil.test/']);
    global $lastLocation;

    return $code === 302 && !isOnList($sub, $other) && isOnList($sub, $list) && str_contains((string)$lastLocation, 'dispatch/preferences')
        ?: "HTTP $code → $lastLocation, other list " . var_export(isOnList($sub, $other), true);
});

echo "\nWebhooks\n";

$mgKey = 'mg-' . bin2hex(random_bytes(8));
$ec = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
$sgKey = preg_replace('/-----[A-Z ]+-----|\s/', '', openssl_pkey_get_details($ec)['key']);

check('every provider refuses when unconfigured (403)', function() {
    foreach (['mailgun', 'postmark', 'sendgrid', 'ses'] as $p) {
        [$code] = raw("/dispatch/webhook/$p", '{"event":"complained"}', ['Content-Type: application/json']);
        if ($code !== 403) {
            return "$p HTTP $code";
        }
    }

    return true;
});

pc('set', array_merge($originalSettings ?? [], [
    'mailgunWebhookSigningKey' => $mgKey,
    'sendgridWebhookPublicKey' => $sgKey,
    'postmarkWebhookUsername' => 'pm-user', 'postmarkWebhookPassword' => 'pm-pass',
    'apiKey' => 'dp-api-key-check',
]));

$bounced = makeSubscriber('bounce');
$complained = makeSubscriber('complain');
$bystander = makeSubscriber('bystander');
logSend($campaign, $bounced, '<bounce-msg@dp.test>');
logSend($campaign, $complained, '<complain-msg@dp.test>');
logSend($campaign, $bystander, '<bystander@dp.test>');

check('Mailgun: a signed complaint marks exactly that subscriber', function() use ($mgKey, $complained, $bystander) {
    $ts = time();
    $tok = bin2hex(random_bytes(12));
    $body = json_encode(['signature' => ['timestamp' => (string)$ts, 'token' => $tok, 'signature' => hash_hmac('sha256', $ts . $tok, $mgKey)],
        'event-data' => ['event' => 'complained', 'message' => ['headers' => ['message-id' => 'complain-msg@dp.test']]], ]);
    [$code, $out] = raw('/dispatch/webhook/mailgun', $body, ['Content-Type: application/json']);

    return $code === 200 && subscriberStatus($complained) === 'complained' && subscriberStatus($bystander) !== 'complained' ?: "HTTP $code $out";
});

check('Mailgun: an unsigned or wrongly signed payload is refused', function() {
    [$a] = raw('/dispatch/webhook/mailgun', json_encode(['event-data' => ['event' => 'complained']]), ['Content-Type: application/json']);
    [$b] = raw('/dispatch/webhook/mailgun', json_encode(['signature' => ['timestamp' => (string)time(), 'token' => 'x', 'signature' => 'y']]), ['Content-Type: application/json']);

    return $a === 403 && $b === 403 ?: "$a / $b";
});

check('SendGrid: a signed bounce is processed; sg_message_id "%" matches nothing', function() use ($ec, $campaign, $bounced, $bystander) {
    $body = json_encode([
        ['event' => 'bounce', 'smtp-id' => '<bounce-msg@dp.test>', 'sg_message_id' => 'zzz.filter'],
        ['event' => 'spamreport', 'sg_message_id' => '%'],
    ]);
    $ts = (string)time();
    openssl_sign($ts . $body, $sig, $ec, OPENSSL_ALGO_SHA256);
    [$code, $out] = raw('/dispatch/webhook/sendgrid', $body, ['Content-Type: application/json', 'X-Twilio-Email-Event-Webhook-Signature: ' . base64_encode($sig), 'X-Twilio-Email-Event-Webhook-Timestamp: ' . $ts]);

    return $code === 200 && logStatus($campaign, $bounced) === 'bounced' && subscriberStatus($bystander) !== 'complained'
        ?: "HTTP $code $out bounce=" . logStatus($campaign, $bounced);
});

check('SendGrid: a bad signature is refused', function() {
    [$code] = raw('/dispatch/webhook/sendgrid', '[]', ['Content-Type: application/json', 'X-Twilio-Email-Event-Webhook-Signature: AAAA', 'X-Twilio-Email-Event-Webhook-Timestamp: 1']);

    return $code === 403 ?: "HTTP $code";
});

check('Postmark: basic auth is required and checked', function() use ($host) {
    [$none] = raw('/dispatch/webhook/postmark', '{"RecordType":"SpamComplaint","MessageID":"x"}', ['Content-Type: application/json']);
    [$bad] = raw('/dispatch/webhook/postmark', '{"RecordType":"SpamComplaint","MessageID":"x"}', ['Content-Type: application/json', 'Authorization: Basic ' . base64_encode('pm-user:nope')]);
    [$good] = raw('/dispatch/webhook/postmark', '{"RecordType":"Open","MessageID":"x"}', ['Content-Type: application/json', 'Authorization: Basic ' . base64_encode('pm-user:pm-pass')]);

    return $none === 403 && $bad === 403 && $good === 200 ?: "none $none, bad $bad, good $good";
});

echo "\nREST API\n";

check('the API accepts its own key and refuses others', function() {
    [$ok] = [http('GET', '/actions/dispatch/api/lists', [], ['Authorization: Bearer dp-api-key-check', 'Accept: application/json'])[0]];
    [$bad] = [http('GET', '/actions/dispatch/api/lists', [], ['Authorization: Bearer wrong', 'Accept: application/json'])[0]];

    return $ok === 200 && $bad === 401 ?: "good key $ok, wrong key $bad";
});

check('API event: fires a sequence trigger with the key; refused without it', function() {
    $c = makeCampaign('api event step', null);
    $seq = new justinholtweb\dispatch\models\Sequence(['name' => DP_PREFIX . ' api event', 'handle' => 'dpcheckapievent' . random_int(100, 999), 'triggerType' => 'event', 'triggerEvent' => 'dpcheck.api']);
    $seq->steps = [new justinholtweb\dispatch\models\SequenceStep(['campaignId' => $c->id, 'delayMinutes' => 60])];
    dp()->sequences->save($seq);
    $s = makeSubscriber('apievent');

    [$none] = http('POST', '/actions/dispatch/api/event', ['email' => $s->email, 'event' => 'dpcheck.api'], ['Accept: application/json']);
    [$bad] = http('POST', '/actions/dispatch/api/event', ['email' => $s->email, 'event' => 'bad key!'], ['Authorization: Bearer dp-api-key-check', 'Accept: application/json']);
    [$ok, $body] = http('POST', '/actions/dispatch/api/event', ['email' => $s->email, 'event' => 'dpcheck.api'], ['Authorization: Bearer dp-api-key-check', 'Accept: application/json']);
    $enrolled = (new craft\db\Query())->from('{{%dispatch_enrolments}}')->where(['sequenceId' => $seq->id, 'subscriberId' => $s->id])->exists();

    return $none === 401 && $bad === 400 && $ok === 200 && (json_decode($body, true)['enrolled'] ?? null) === 1 && $enrolled
        ?: "no key $none, bad event $bad, ok $ok $body";
});

pc('set', $originalSettings ?? []);

$cp = '/admin';

echo "\nSigned in\n";
check('the harness admin can sign in', function() use ($cp, $user, $pass) {
    [, $html] = http('GET', "$cp/login");
    [$code, $body] = http('POST', "$cp/actions/users/login", [
        'CRAFT_CSRF_TOKEN' => csrf($html), 'loginName' => $user, 'password' => $pass,
    ], ['Accept: application/json']);
    if ($code === 200 && str_contains($body, '"returnUrl"')) {
        return true;
    }
    // The shared harness's password login is unreliable (sibling plugins hook the login). Craft's
    // own impersonation link signs in without one.
    exec('php craft users/impersonate ' . escapeshellarg($user) . ' 2>&1', $out);
    if (!preg_match('#https?://\S+#', implode("\n", $out), $m)) {
        return 'password login failed and no impersonation URL: ' . implode(' ', $out);
    }
    $url = parse_url($m[0]);
    http('GET', $url['path'] . '?' . ($url['query'] ?? ''));
    [$code] = http('GET', "$cp/dashboard");
    return $code === 200 ?: "impersonation did not sign in (dashboard HTTP $code)";
});



check('campaign edit page has no nested forms; Send/Duplicate are formsubmit buttons', function() use ($campaign) {
    [$code, $html] = http('GET', '/admin/dispatch/campaigns/' . $campaign->id);

    return $code === 200 && substr_count(strtolower($html), '<form') <= 2
        && str_contains($html, 'data-action="dispatch/campaigns/send"') && !str_contains($html, 'value="dispatch/campaigns/duplicate"')
        ?: "HTTP $code, forms " . substr_count(strtolower($html), '<form');
});

check('Preview of a campaign using craft.app says why, instead of leaking or 500ing', function() use ($list) {
    $evil = makeCampaign('evil preview', $list, '{{ craft.app.config.general.securityKey }}');
    [$code, $html] = http('GET', '/admin/actions/dispatch/campaigns/preview?campaignId=' . $evil->id);
    $key = Craft::$app->getConfig()->getGeneral()->securityKey;

    return $code === 422 && !str_contains($html, $key) && str_contains($html, 'not allowed') ?: "HTTP $code";
});

check('importing a non-CSV upload is refused', function() use ($list) {
    global $base, $jar, $host;
    [, $page] = http('GET', '/admin/dispatch/subscribers/import');
    $tmp = tempnam(sys_get_temp_dir(), 'dp') . '.php';
    file_put_contents($tmp, "<?php echo 'x';");
    $ch = curl_init($base . '/admin/actions/dispatch/subscribers/upload-import');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_USERAGENT => 'dispatch-http-checks', CURLOPT_RESOLVE => ["$host:443:127.0.0.1", "$host:80:127.0.0.1"],
        CURLOPT_POSTFIELDS => ['CRAFT_CSRF_TOKEN' => csrf($page), 'mailingListId' => $list->id, 'csvFile' => new CURLFile($tmp, 'application/x-php', 'evil.php')], ]);
    curl_exec($ch);
    $queued = (new craft\db\Query())->from('{{%queue}}')->where(['like', 'job', 'dispatch-import-'])->andWhere(['>', 'timePushed', time() - 30])->exists();
    @unlink($tmp);

    return !$queued;
});

echo "\nPro screens (admin)\n";

check('segments: the builder renders, a posted rule set saves, and the count shows', function() use ($list) {
    [$code, $html] = http('GET', '/admin/dispatch/segments/new');
    if ($code !== 200 || !str_contains($html, 'condition-container')) {
        return "new page HTTP $code";
    }
    $handle = 'dpcheckseg' . random_int(1000, 9999);
    [$save] = http('POST', '/admin/actions/dispatch/segments/save', [
        'CRAFT_CSRF_TOKEN' => csrf($html), 'name' => DP_PREFIX . ' http segment', 'handle' => $handle,
        // A posted condition class is ignored: segments are always SubscriberConditions.
        'condition' => ['class' => 'craft\\elements\\conditions\\users\\UserCondition', 'conditionRules' => [
            ['class' => justinholtweb\dispatch\elements\conditions\MailingListConditionRule::class, 'operator' => 'in', 'values' => [(string)$list->id]],
        ]],
    ]);
    $segment = dp()->segments->getByHandle($handle);
    [$editCode, $edit] = $segment ? http('GET', '/admin/dispatch/segments/' . $segment->id) : [0, ''];

    return $save === 302 && $segment && count($segment->getCondition()->getConditionRules()) === 1
        && $editCode === 200 && str_contains($edit, 'right now') ?: "save $save, edit $editCode";
});

check('sequences: the editor renders and saves a list-triggered sequence with steps', function() use ($list, $campaign) {
    [$code, $html] = http('GET', '/admin/dispatch/sequences/new');
    $handle = 'dpcheckseq' . random_int(1000, 9999);
    [$save] = http('POST', '/admin/actions/dispatch/sequences/save', [
        'CRAFT_CSRF_TOKEN' => csrf($html), 'name' => DP_PREFIX . ' http sequence', 'handle' => $handle, 'enabled' => '1',
        'triggerType' => 'list', 'triggerListId' => $list->id,
        'steps' => ['new1' => ['campaignId' => $campaign->id, 'delay' => 2, 'unit' => 'days']],
    ]);
    $seq = dp()->sequences->getByHandle($handle);
    $steps = $seq ? dp()->sequences->getSteps($seq->id) : [];

    return $code === 200 && $save === 302 && count($steps) === 1 && $steps[0]->delayMinutes === 2880 ?: "page $code, save $save";
});

check('a POST without a CSRF token is refused', function() {
    [$code] = http('POST', '/admin/actions/dispatch/sequences/save', ['name' => 'x', 'handle' => 'x']);

    return $code === 400 ?: "HTTP $code";
});

check('integrations: an endpoint saves; Send test to a private address is refused and logged, not sent', function() {
    [, $html] = http('GET', '/admin/dispatch/integrations/new');
    [$save] = http('POST', '/admin/actions/dispatch/integrations/save', [
        'CRAFT_CSRF_TOKEN' => csrf($html), 'name' => DP_PREFIX . ' http endpoint', 'url' => 'http://127.0.0.1:8080/hook',
        'secret' => 'whsec_http', 'enabled' => '1', 'events' => ['subscriber.subscribed'],
    ]);
    $id = (int)(new craft\db\Query())->select('id')->from('{{%dispatch_endpoints}}')->where(['name' => DP_PREFIX . ' http endpoint'])->scalar();
    [, $edit] = http('GET', '/admin/dispatch/integrations/' . $id);
    [$test] = http('POST', '/admin/actions/dispatch/integrations/test', ['CRAFT_CSRF_TOKEN' => csrf($edit), 'endpointId' => $id]);
    $log = (new craft\db\Query())->from('{{%dispatch_deliveries}}')->where(['endpointId' => $id, 'event' => 'ping'])->one();

    return $save === 302 && $id && $test === 302 && $log && $log['status'] === 'failed' && str_contains((string)$log['error'], 'public')
        ?: "save $save, test $test, log " . json_encode($log);
});

check('campaign edit has the segment picker and A/B section; A/B settings save', function() use ($list) {
    $c = makeCampaign('http ab', $list);
    [$code, $html] = http('GET', '/admin/dispatch/campaigns/' . $c->id);
    [$save] = http('POST', '/admin/actions/dispatch/campaigns/save', [
        'CRAFT_CSRF_TOKEN' => csrf($html), 'campaignId' => $c->id, 'title' => $c->title, 'subject' => 'A subject',
        'mailingListId' => $list->id, 'segmentId' => '', 'abEnabled' => '1', 'abSubjects' => ['new1' => ['subject' => 'B subject']],
        'abTestPercent' => 30, 'abWaitHours' => 1.5, 'abMetric' => 'clicks',
    ]);
    $saved = Craft::$app->getElements()->getElementById($c->id, justinholtweb\dispatch\elements\Campaign::class);

    return $code === 200 && str_contains($html, 'name="segmentId"') && str_contains($html, 'abSubjects') && substr_count(strtolower($html), '<form') <= 2
        && $save === 302 && $saved->abEnabled && $saved->getAbSubjects() === ['B subject'] && $saved->abTestPercent === 30
        && $saved->abWaitMinutes === 90 && $saved->abMetric === 'clicks' ?: "page $code, save $save";
});

check('the Pro index and edit screens render with data in them', function() use ($list, $sub) {
    $seqId = (int)(new craft\db\Query())->select('id')->from('{{%dispatch_sequences}}')->where(['like', 'name', DP_PREFIX . '%', false])->scalar();
    $endpointId = (int)(new craft\db\Query())->select('id')->from('{{%dispatch_endpoints}}')->where(['like', 'name', DP_PREFIX . '%', false])->scalar();

    // A decided A/B campaign, so the results table renders.
    $c = makeCampaign('http ab results', $list);
    $c->subject = 'Subject A';
    $c->abEnabled = true;
    $c->setAbSubjects(['Subject B']);
    $c->campaignStatus = 'sent';
    $c->abStatus = 'decided';
    $c->abWinner = 1;
    Craft::$app->getElements()->saveElement($c);
    logSend($c, $sub, '<abres@dp.test>');
    craft\helpers\Db::update('{{%dispatch_sendlog}}', ['variant' => 1], ['campaignId' => $c->id]);

    $pages = [
        '/admin/dispatch/segments' => DP_PREFIX . ' http segment',
        '/admin/dispatch/sequences' => DP_PREFIX . ' http sequence',
        "/admin/dispatch/sequences/$seqId" => 'Progress',
        '/admin/dispatch/integrations' => DP_PREFIX . ' http endpoint',
        "/admin/dispatch/integrations/$endpointId" => 'Recent deliveries',
        '/admin/dispatch/campaigns/' . $c->id => 'Winner',
        '/admin/dispatch/subscribers/' . $sub->id => 'Mailing Lists',
        '/admin/dispatch/settings' => 'Subscriber Fields',
    ];

    foreach ($pages as $path => $needle) {
        [$code, $html] = http('GET', $path);
        if ($code !== 200 || !str_contains($html, $needle)) {
            return "$path → HTTP $code" . ($code === 200 ? ", missing “{$needle}”" : '');
        }
    }

    return true;
});

check('the Subscribers index still loads, and its filter HUD offers the segment rules', function() use ($sub) {
    [, $page] = http('GET', '/admin/dispatch/subscribers');
    $t = csrf($page);
    $headers = ['Accept: application/json', 'X-CSRF-Token: ' . $t, 'X-Requested-With: XMLHttpRequest'];
    [$code, $body] = http('POST', '/admin/actions/element-indexes/get-elements', ['elementType' => justinholtweb\dispatch\elements\Subscriber::class, 'source' => '*', 'context' => 'index', 'viewState' => ['mode' => 'table', 'static' => false]], $headers);
    [$hudCode, $hud] = http('POST', '/admin/actions/element-indexes/filter-hud', ['elementType' => justinholtweb\dispatch\elements\Subscriber::class, 'source' => '*', 'id' => 'dpcheck', 'conditionConfig' => ['class' => justinholtweb\dispatch\elements\conditions\SubscriberCondition::class]], $headers);

    return $code === 200 && str_contains($body, (string)$sub->email) && $hudCode === 200 && str_contains($hud, 'Mailing list')
        ?: "index $code " . substr(strip_tags($body), 0, 600) . ", hud $hudCode";
});

check('subscriber fields settings render for an admin', function() {
    [$code, $html] = http('GET', '/admin/dispatch/settings/subscriber-fields');

    return $code === 200 && str_contains($html, 'layoutdesigner') ?: "HTTP $code";
});

check('an admin saving one settings page keeps the other page\'s settings and ignores unknown keys', function() use ($originalSettings) {
    pc('set', array_merge($originalSettings ?? [], ['sesTopicArn' => 'arn:aws:sns:us-east-1:1:keep']));
    [, $page] = http('GET', '/admin/dispatch/settings');
    [$code] = http('POST', '/admin/actions/dispatch/campaigns/save-settings', [
        'CRAFT_CSRF_TOKEN' => csrf($page), 'settings' => ['defaultFromName' => 'DP check sender', 'transportSettings' => ['x' => ['apiKey' => 'sneak']]],
    ]);
    $after = pc('get') ?? [];
    pc('set', $originalSettings ?? []);

    return in_array($code, [200, 302], true) && ($after['defaultFromName'] ?? '') === 'DP check sender'
        && ($after['sesTopicArn'] ?? '') === 'arn:aws:sns:us-east-1:1:keep' && !isset($after['transportSettings'])
        ?: "HTTP $code " . json_encode($after);
});

check('the migration moves webhookSecret to apiKey and scrubs transport credentials', function() use ($originalSettings) {
    pc('set', array_merge($originalSettings ?? [], ['webhookSecret' => 'old-secret', 'transportType' => 'mailgun', 'transportSettings' => ['mailgun' => ['apiKey' => 'key-live-123']]]));
    $after = pc('migrate') ?? [];
    pc('set', $originalSettings ?? []);

    return ($after['apiKey'] ?? null) === 'old-secret' && !isset($after['webhookSecret']) && !isset($after['transportSettings']) && !isset($after['transportType'])
        ?: json_encode($after);
});

check('a non-admin with dispatch:manageSettings cannot save settings', function() use ($originalSettings) {
    global $jar;
    $user = new craft\elements\User(['username' => 'dpchecksettings', 'email' => 'dpchecksettings@example.test', 'newPassword' => 'Dp-check-pass-2026', 'active' => true]);
    Craft::$app->getElements()->saveElement($user, false);
    Craft::$app->getUserPermissions()->saveUserPermissions($user->id, ['accesscp', 'accessplugin-dispatch', 'dispatch:accessplugin', 'dispatch:managesettings']);

    $jar = tempnam(sys_get_temp_dir(), 'dp');
    http('POST', '/actions/users/login', ['CRAFT_CSRF_TOKEN' => token(), 'loginName' => 'dpchecksettings', 'password' => 'Dp-check-pass-2026'], ['Accept: application/json']);
    [$pageCode, $page] = http('GET', '/admin/dispatch/settings');
    [$code] = http('POST', '/admin/actions/dispatch/campaigns/save-settings', ['CRAFT_CSRF_TOKEN' => csrf($page) ?? token(), 'settings' => ['defaultFromName' => 'hijacked']], ['Accept: application/json']);
    $after = pc('get') ?? [];
    Craft::$app->getElements()->deleteElement($user, true);

    return $pageCode === 200 && $code === 403 && ($after['defaultFromName'] ?? '') !== 'hijacked' ?: "page $pageCode, save $code";
});

echo "\nPro screens (permissions)\n";

check('a user without the Pro permissions (or admin) is refused segments, sequences and integrations', function() use ($campaign) {
    global $jar;
    $user = new craft\elements\User(['username' => 'dpcheckpro', 'email' => 'dpcheckpro@example.test', 'newPassword' => 'Dp-check-pass-2026', 'active' => true]);
    Craft::$app->getElements()->saveElement($user, false);
    Craft::$app->getUserPermissions()->saveUserPermissions($user->id, ['accesscp', 'accessplugin-dispatch', 'dispatch:accessplugin', 'dispatch:managecampaigns', 'dispatch:managesettings']);

    $jar = tempnam(sys_get_temp_dir(), 'dp');
    http('POST', '/actions/users/login', ['CRAFT_CSRF_TOKEN' => token(), 'loginName' => 'dpcheckpro', 'password' => 'Dp-check-pass-2026'], ['Accept: application/json']);
    [$control, $page] = http('GET', '/admin/dispatch/campaigns/' . $campaign->id);
    $t = csrf($page) ?? token();
    $codes = [];
    foreach (['/admin/dispatch/segments', '/admin/dispatch/sequences', '/admin/dispatch/integrations', '/admin/dispatch/settings/subscriber-fields'] as $path) {
        $codes[$path] = http('GET', $path)[0];
    }
    $codes['save segment'] = http('POST', '/admin/actions/dispatch/segments/save', ['CRAFT_CSRF_TOKEN' => $t, 'name' => 'x', 'handle' => 'dpcheckdenied'], ['Accept: application/json'])[0];
    $codes['save sequence'] = http('POST', '/admin/actions/dispatch/sequences/save', ['CRAFT_CSRF_TOKEN' => $t, 'name' => 'x', 'handle' => 'dpcheckdenied'], ['Accept: application/json'])[0];
    $codes['save endpoint'] = http('POST', '/admin/actions/dispatch/integrations/save', ['CRAFT_CSRF_TOKEN' => $t, 'name' => DP_PREFIX . ' denied', 'url' => 'https://example.com/', 'secret' => 'x', 'events' => ['subscriber.subscribed']], ['Accept: application/json'])[0];
    $codes['field layout'] = http('POST', '/admin/actions/dispatch/campaigns/save-subscriber-fields', ['CRAFT_CSRF_TOKEN' => $t], ['Accept: application/json'])[0];

    // Granted the segment permission, the same user gets in (proves the refusals above weren't a dead session).
    Craft::$app->getUserPermissions()->saveUserPermissions($user->id, ['accesscp', 'accessplugin-dispatch', 'dispatch:accessplugin', 'dispatch:managecampaigns', 'dispatch:managesegments']);
    [$granted] = http('GET', '/admin/dispatch/segments');

    $created = (new craft\db\Query())->from('{{%dispatch_endpoints}}')->where(['name' => DP_PREFIX . ' denied'])->exists()
        || (new craft\db\Query())->from('{{%dispatch_segments}}')->where(['handle' => 'dpcheckdenied'])->exists()
        || (new craft\db\Query())->from('{{%dispatch_sequences}}')->where(['handle' => 'dpcheckdenied'])->exists();
    Craft::$app->getElements()->deleteElement($user, true);

    return $control === 200 && $granted === 200 && !$created && array_unique(array_values($codes)) === [403] ?: json_encode([$control, $granted, $codes]);
});

sweep();
pc('set', $originalSettings ?? []);

echo "\n$passed passed, $failed failed\n";
@unlink($jar);
exit($failed === 0 ? 0 : 1);
