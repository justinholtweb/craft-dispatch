<?php
/**
 * Dispatch integration checks — the 5.1 security fixes, through the real services.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-dispatch/tests/integration/checks.php
 *
 * The request-level half (real links, webhooks and CP posts over HTTP) is http.php.
 * Settings are changed in memory only.
 */

$root = getcwd();
require $root . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
require __DIR__ . '/_fixtures.php';

use justinholtweb\dispatch\helpers\TrackingHelper as T;
use justinholtweb\dispatch\services\WebhookVerifier;

$settings = dp()->getSettings();
$original = $settings->toArray();

sweep();

$list = makeList('main');
$sub = makeSubscriber('a', ['firstName' => 'Ada']);
dp()->subscribers->subscribe($sub->id, $list->id);

// -----------------------------------------------------------------------------------------------
section('Campaign Twig is sandboxed');

check('subscriber and campaign values still render', function() use ($list, $sub) {
    $c = makeCampaign('ok', $list, '<p>Hello {{ subscriber.firstName }} — {{ campaign.title }}{% if subscriber %}!{% endif %}</p>');
    $html = dp()->sender->renderEmail($c, $sub);

    return str_contains($html, 'Hello Ada — ' . DP_PREFIX . ' ok!') ?: substr(strip_tags($html), 0, 200);
});

check('fullName and campaign.mailingList.title work', function() use ($list, $sub) {
    $c = makeCampaign('getters', $list, '[{{ subscriber.fullName }}|{{ campaign.mailingList.title }}]');
    $html = dp()->sender->renderEmail($c, $sub);

    return str_contains($html, '|' . DP_PREFIX . ' main]') ?: substr(strip_tags($html), 0, 200);
});

check('methods outside the allowlist are refused (e.g. dumping the whole list)', function() use ($list, $sub) {
    $c = makeCampaign('dump', $list, '{% for s in campaign.mailingList.getSubscribers() %}{{ s.email }}{% endfor %}');

    try {
        dp()->sender->renderEmail($c, $sub);

        return 'rendered';
    } catch (Twig\Sandbox\SecurityError) {
        return true;
    }
});

check('craft.app is refused', function() use ($list, $sub) {
    $c = makeCampaign('evil', $list, '{{ craft.app.config.general.securityKey }}');

    try {
        dp()->sender->renderEmail($c, $sub);

        return 'rendered';
    } catch (Twig\Sandbox\SecurityError) {
        return true;
    }
});

check('the settings object is not in the body context', function() use ($list, $sub) {
    $c = makeCampaign('settings', $list, '[{{ settings is defined ? "LEAK" : "none" }}]');

    return str_contains(dp()->sender->renderEmail($c, $sub), '[none]');
});

check('the subject is sandboxed too', function() {
    try {
        dp()->sender->renderEditorTwig('{{ craft.app.config.general.securityKey }}', []);

        return 'rendered';
    } catch (Twig\Sandbox\SecurityError) {
        return true;
    }
});

check('rendered email carries dtoken links, never ?token=', function() use ($list, $sub, $settings) {
    $settings->enableTracking = $settings->trackOpens = $settings->trackClicks = true;
    $c = makeCampaign('links', $list, '<p><a href="https://example.com/a?x=1&amp;y=2">link</a> <a href="{{ unsubscribeUrl }}">u</a> <a href="{{ preferencesUrl }}">p</a></p>');
    $html = dp()->sender->renderEmail($c, $sub);

    return !preg_match('/[?&](amp;)?token=/', $html) && substr_count($html, 'dtoken=') >= 4 ?: 'token= found or dtoken missing';
});

// -----------------------------------------------------------------------------------------------
section('Signed links');

check('a token verifies only for its own purpose', function() {
    $t = T::sign(T::PURPOSE_OPEN, 5, 9);

    return T::verify($t, T::PURPOSE_OPEN, 5, 9)
        && !T::verify($t, T::PURPOSE_UNSUBSCRIBE, 5, 9)
        && !T::verify($t, T::PURPOSE_PREFERENCES, 5)
        && !T::verify($t, T::PURPOSE_OPEN, 5, 10);
});

check('the old pixel-token-as-unsubscribe-token trick fails for new tokens', function() {
    // Pixel for campaign 7 / subscriber 3 used to equal unsubscribe for subscriber 7 / list 3.
    return !T::verify(T::sign(T::PURPOSE_OPEN, 7, 3), T::PURPOSE_UNSUBSCRIBE, 7, 3);
});

$legacy = static fn(...$p) => hash_hmac('sha256', implode(':', $p), Craft::$app->getConfig()->getGeneral()->securityKey . ':dispatch');

check('pre-5.1 tokens still work for unsubscribe, opens and clicks', function() use ($legacy) {
    return T::verify($legacy(3, 8), T::PURPOSE_UNSUBSCRIBE, 3, 8)
        && T::verify($legacy(4, 3), T::PURPOSE_OPEN, 4, 3)
        && T::verify($legacy(4, 3, 'https://x.test/'), T::PURPOSE_CLICK, 4, 3, 'https://x.test/');
});

check('…but not for preferences, which can add someone to lists', function() use ($legacy) {
    return !T::verify($legacy(3, 0), T::PURPOSE_PREFERENCES, 3);
});

check('an empty token never verifies', function() {
    return !T::verify('', T::PURPOSE_OPEN, 1, 1);
});

// -----------------------------------------------------------------------------------------------
section('Webhook verification');

$v = new WebhookVerifier();

check('every provider fails closed when unconfigured', function() use ($v, $settings) {
    foreach (['mailgunWebhookSigningKey', 'postmarkWebhookUsername', 'postmarkWebhookPassword', 'sendgridWebhookPublicKey', 'sesTopicArn'] as $k) {
        $settings->$k = '';
    }

    return !$v->verifyMailgun('{}') && !$v->verifySendgrid('[]', 'x', '1') && !$v->verifySns('{}');
});

$mgKey = 'key-' . bin2hex(random_bytes(8));
$mailgun = static function(int $ts, string $token, string $key) {
    return json_encode(['signature' => ['timestamp' => (string)$ts, 'token' => $token, 'signature' => hash_hmac('sha256', $ts . $token, $key)], 'event-data' => ['event' => 'failed']]);
};

check('Mailgun: a correctly signed payload passes', function() use ($v, $settings, $mgKey, $mailgun) {
    $settings->mailgunWebhookSigningKey = $mgKey;

    return $v->verifyMailgun($mailgun(time(), bin2hex(random_bytes(12)), $mgKey)) ?: $v->lastError;
});

check('Mailgun: wrong key, stale timestamp and a replayed token are refused', function() use ($v, $mgKey, $mailgun) {
    $replay = $mailgun(time(), bin2hex(random_bytes(12)), $mgKey);

    return !$v->verifyMailgun($mailgun(time(), 'abc', 'wrong'))
        && !$v->verifyMailgun($mailgun(time() - 3600, 'def', $mgKey))
        && $v->verifyMailgun($replay) && !$v->verifyMailgun($replay);
});

check('Mailgun: the signing key may be an environment variable', function() use ($v, $settings, $mailgun) {
    putenv('DP_CHECK_MG=envkey123');
    $_SERVER['DP_CHECK_MG'] = 'envkey123';
    $settings->mailgunWebhookSigningKey = '$DP_CHECK_MG';
    $ok = $v->verifyMailgun($mailgun(time(), bin2hex(random_bytes(12)), 'envkey123'));
    putenv('DP_CHECK_MG');
    unset($_SERVER['DP_CHECK_MG']);

    return $ok ?: $v->lastError;
});

$ec = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
$ecPublic = openssl_pkey_get_details($ec)['key'];
$sgBase64 = preg_replace('/-----[A-Z ]+-----|\s/', '', $ecPublic);

check('SendGrid: an ECDSA-signed payload passes (base64 key, as SendGrid shows it)', function() use ($v, $settings, $ec, $sgBase64) {
    $settings->sendgridWebhookPublicKey = $sgBase64;
    $body = '[{"event":"bounce","smtp-id":"<x@y>"}]';
    $ts = (string)time();
    openssl_sign($ts . $body, $sig, $ec, OPENSSL_ALGO_SHA256);

    return $v->verifySendgrid($body, base64_encode($sig), $ts) ?: $v->lastError;
});

check('SendGrid: a tampered body or another key is refused', function() use ($v, $ec) {
    $ts = (string)time();
    openssl_sign($ts . '[]', $sig, $ec, OPENSSL_ALGO_SHA256);
    $other = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    openssl_sign($ts . '[]', $sig2, $other, OPENSSL_ALGO_SHA256);

    return !$v->verifySendgrid('[{"x":1}]', base64_encode($sig), $ts) && !$v->verifySendgrid('[]', base64_encode($sig2), $ts);
});

// A self-signed "Amazon" certificate, served through the injectable fetcher.
$snsKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$csr = openssl_csr_new(['commonName' => 'sns.amazonaws.com'], $snsKey);
openssl_x509_export(openssl_csr_sign($csr, null, $snsKey, 1), $snsCert);
$topic = 'arn:aws:sns:us-east-1:123456789012:dp-check';
$snsMessage = static function(array $m) use ($snsKey) {
    $m += ['SignatureVersion' => '2', 'SigningCertURL' => 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-abc.pem'];
    openssl_sign(WebhookVerifier::snsStringToSign($m), $sig, $snsKey, OPENSSL_ALGO_SHA256);
    $m['Signature'] = base64_encode($sig);

    return $m;
};

check('SES/SNS: a signed notification from the configured topic passes', function() use ($settings, $snsCert, $topic, $snsMessage) {
    $settings->sesTopicArn = $topic;
    $v = new WebhookVerifier(['certificateFetcher' => fn() => $snsCert]);
    $m = $snsMessage(['Type' => 'Notification', 'MessageId' => 'm1', 'TopicArn' => $topic, 'Message' => '{"notificationType":"Bounce"}', 'Timestamp' => '2026-09-29T00:00:00Z']);

    return $v->verifySns(json_encode($m)) ?: $v->lastError;
});

check('SES/SNS: another topic, a forged signature, or a non-AWS certificate host is refused', function() use ($snsCert, $topic, $snsMessage) {
    $v = new WebhookVerifier(['certificateFetcher' => fn() => $snsCert]);
    $base = ['Type' => 'Notification', 'MessageId' => 'm1', 'Message' => '{}', 'Timestamp' => '2026-09-29T00:00:00Z'];

    $otherTopic = $snsMessage($base + ['TopicArn' => 'arn:aws:sns:us-east-1:999:evil']);
    $forged = $snsMessage($base + ['TopicArn' => $topic]);
    $forged['Message'] = '{"notificationType":"Complaint"}';
    $evilHost = $snsMessage($base + ['TopicArn' => $topic, 'SigningCertURL' => 'https://sns.us-east-1.amazonaws.com.evil.test/x.pem']);

    return !$v->verifySns(json_encode($otherTopic)) && !$v->verifySns(json_encode($forged)) && !$v->verifySns(json_encode($evilHost));
});

check('SNS URL check accepts only https://sns.<region>.amazonaws.com', function() {
    return WebhookVerifier::isAwsSnsUrl('https://sns.eu-west-1.amazonaws.com/x.pem')
        && WebhookVerifier::isAwsSnsUrl('https://sns.cn-north-1.amazonaws.com.cn/x.pem')
        && !WebhookVerifier::isAwsSnsUrl('http://sns.eu-west-1.amazonaws.com/x.pem')
        && !WebhookVerifier::isAwsSnsUrl('https://sns.eu-west-1.amazonaws.com.evil.test/x.pem')
        && !WebhookVerifier::isAwsSnsUrl('https://evil.test@sns.eu-west-1.amazonaws.com/x.pem')
        && !WebhookVerifier::isAwsSnsUrl('https://sns.eu-west-1.amazonaws.com:8443/x.pem');
});

// -----------------------------------------------------------------------------------------------
section('Exports and settings');

check('subscriber CSV export neutralises formula cells', function() use ($list) {
    $s = makeSubscriber('csv', ['firstName' => '=HYPERLINK("http://evil.test","x")']);
    dp()->subscribers->subscribe($s->id, $list->id);
    $csv = dp()->subscribers->exportCsv($list->id);

    return str_contains($csv, "'=HYPERLINK") && !preg_match('/,=HYPERLINK/', $csv) ?: $csv;
});

check('the transport credential settings are gone from the model', function() use ($settings) {
    return !property_exists($settings, 'transportSettings') && !property_exists($settings, 'webhookSecret');
});

check('a secret setting resolves an environment variable', function() use ($settings) {
    $_SERVER['DP_CHECK_API'] = 'secret-from-env';
    putenv('DP_CHECK_API=secret-from-env');
    $settings->apiKey = '$DP_CHECK_API';
    $resolved = $settings->resolved('apiKey');
    putenv('DP_CHECK_API');
    unset($_SERVER['DP_CHECK_API']);

    return $resolved === 'secret-from-env' ?: $resolved;
});

// -----------------------------------------------------------------------------------------------
sweep();
$settings->setAttributes($original, false);

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
