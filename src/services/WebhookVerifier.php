<?php

namespace justinholtweb\dispatch\services;

use Craft;
use craft\base\Component;
use craft\web\Request;
use justinholtweb\dispatch\Plugin;

/**
 * Proves a bounce/complaint webhook really came from the provider — each by its own scheme.
 *
 * Every method **fails closed**: an unconfigured credential is a refusal, not a pass. (Before 5.1
 * the check was skipped whenever no secret was set, which was always, so anybody could post
 * "complaint" events and suppress the list.)
 *
 * - Mailgun — HMAC-SHA256 of `timestamp . token` with the webhook signing key; stale timestamps
 *   and reused tokens are refused.
 * - Postmark — HTTP Basic credentials, which Postmark sends when they are put in the webhook URL.
 * - SendGrid — ECDSA signature over `timestamp . body` with the Signed Event Webhook public key.
 * - SES — an SNS message signature, checked against Amazon's signing certificate (fetched only
 *   from `https://sns.<region>.amazonaws.com/`), from the one configured topic.
 */
class WebhookVerifier extends Component
{
    /** How far a Mailgun timestamp may drift from now, in seconds. */
    public const MAILGUN_MAX_AGE = 900;

    /**
     * Fetches an SNS signing certificate (PEM) by URL. Replaceable in tests; the URL is validated
     * before this is ever called.
     *
     * @var callable(string): ?string|null
     */
    public $certificateFetcher = null;

    /** Why the last verification failed — for the log, never for the response. */
    public ?string $lastError = null;

    public function verify(string $provider, Request $request): bool
    {
        $this->lastError = null;

        return match ($provider) {
            'mailgun' => $this->verifyMailgun($request->getRawBody()),
            'postmark' => $this->verifyPostmark($request),
            'sendgrid' => $this->verifySendgrid(
                $request->getRawBody(),
                (string)$request->getHeaders()->get('X-Twilio-Email-Event-Webhook-Signature', ''),
                (string)$request->getHeaders()->get('X-Twilio-Email-Event-Webhook-Timestamp', ''),
            ),
            'ses' => $this->verifySns($request->getRawBody()),
            default => $this->fail("unknown provider $provider"),
        };
    }

    public function verifyMailgun(string $rawBody): bool
    {
        $key = Plugin::getInstance()->getSettings()->resolved('mailgunWebhookSigningKey');

        if ($key === '') {
            return $this->fail('Mailgun webhook signing key is not configured');
        }

        $data = json_decode($rawBody, true);
        $sig = is_array($data) ? ($data['signature'] ?? null) : null;

        if (!is_array($sig) || !isset($sig['timestamp'], $sig['token'], $sig['signature'])) {
            return $this->fail('no Mailgun signature block');
        }

        if (abs(time() - (int)$sig['timestamp']) > self::MAILGUN_MAX_AGE) {
            return $this->fail('stale Mailgun timestamp');
        }

        $expected = hash_hmac('sha256', $sig['timestamp'] . $sig['token'], $key);

        if (!hash_equals($expected, (string)$sig['signature'])) {
            return $this->fail('bad Mailgun signature');
        }

        // A captured request is valid for the whole window; the token makes it single-use.
        $cacheKey = 'dispatch:mailgun-token:' . sha1((string)$sig['token']);

        if (Craft::$app->getCache()->get($cacheKey)) {
            return $this->fail('replayed Mailgun token');
        }

        Craft::$app->getCache()->set($cacheKey, 1, self::MAILGUN_MAX_AGE * 2);

        return true;
    }

    public function verifyPostmark(Request $request): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        $user = $settings->resolved('postmarkWebhookUsername');
        $pass = $settings->resolved('postmarkWebhookPassword');

        if ($user === '' || $pass === '') {
            return $this->fail('Postmark webhook credentials are not configured');
        }

        [$givenUser, $givenPass] = $request->getAuthCredentials();

        if (!hash_equals($user, (string)$givenUser) || !hash_equals($pass, (string)$givenPass)) {
            return $this->fail('bad Postmark credentials');
        }

        return true;
    }

    public function verifySendgrid(string $rawBody, string $signature, string $timestamp): bool
    {
        $key = Plugin::getInstance()->getSettings()->resolved('sendgridWebhookPublicKey');

        if ($key === '') {
            return $this->fail('SendGrid verification key is not configured');
        }

        if ($signature === '' || $timestamp === '') {
            return $this->fail('no SendGrid signature headers');
        }

        $pem = str_contains($key, 'BEGIN PUBLIC KEY')
            ? $key
            : "-----BEGIN PUBLIC KEY-----\n" . chunk_split(preg_replace('/\s+/', '', $key), 64, "\n") . "-----END PUBLIC KEY-----\n";

        $publicKey = @openssl_pkey_get_public($pem);

        if ($publicKey === false) {
            return $this->fail('SendGrid verification key is not a valid public key');
        }

        $decoded = base64_decode($signature, true);

        if ($decoded === false || openssl_verify($timestamp . $rawBody, $decoded, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            return $this->fail('bad SendGrid signature');
        }

        return true;
    }

    public function verifySns(string $rawBody): bool
    {
        $topic = Plugin::getInstance()->getSettings()->resolved('sesTopicArn');

        if ($topic === '') {
            return $this->fail('SES topic ARN is not configured');
        }

        $message = json_decode($rawBody, true);

        if (!is_array($message) || !isset($message['Type'], $message['Signature'], $message['SigningCertURL'], $message['TopicArn'])) {
            return $this->fail('not an SNS message');
        }

        if (!hash_equals($topic, (string)$message['TopicArn'])) {
            return $this->fail('SNS message from an unexpected topic');
        }

        $certUrl = (string)$message['SigningCertURL'];

        if (!self::isAwsSnsUrl($certUrl) || !str_ends_with(parse_url($certUrl, PHP_URL_PATH) ?: '', '.pem')) {
            return $this->fail('SNS signing certificate is not on an Amazon SNS host');
        }

        $stringToSign = self::snsStringToSign($message);

        if ($stringToSign === null) {
            return $this->fail('unsupported SNS message type');
        }

        $certificate = $this->fetchCertificate($certUrl);

        if ($certificate === null) {
            return $this->fail('could not fetch the SNS signing certificate');
        }

        $algo = ($message['SignatureVersion'] ?? '1') === '2' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;
        $signature = base64_decode((string)$message['Signature'], true);

        if ($signature === false || openssl_verify($stringToSign, $signature, $certificate, $algo) !== 1) {
            return $this->fail('bad SNS signature');
        }

        return true;
    }

    /** `https://sns.<region>.amazonaws.com[.cn]/…` and nothing else. */
    public static function isAwsSnsUrl(string $url): bool
    {
        $parts = parse_url($url);

        return ($parts['scheme'] ?? '') === 'https'
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port'])
            && (bool)preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?$/', strtolower((string)($parts['host'] ?? '')));
    }

    /**
     * The canonical string SNS signs, per AWS's documented field order.
     *
     * @param array<string, mixed> $m
     */
    public static function snsStringToSign(array $m): ?string
    {
        $fields = match ($m['Type'] ?? null) {
            'Notification' => isset($m['Subject'])
                ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type']
                : ['Message', 'MessageId', 'Timestamp', 'TopicArn', 'Type'],
            'SubscriptionConfirmation', 'UnsubscribeConfirmation' => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
            default => null,
        };

        if ($fields === null) {
            return null;
        }

        $out = '';

        foreach ($fields as $field) {
            if (!isset($m[$field])) {
                return null;
            }

            $out .= $field . "\n" . $m[$field] . "\n";
        }

        return $out;
    }

    private function fetchCertificate(string $url): ?string
    {
        if ($this->certificateFetcher !== null) {
            return ($this->certificateFetcher)($url);
        }

        $cacheKey = 'dispatch:sns-cert:' . sha1($url);
        $cached = Craft::$app->getCache()->get($cacheKey);

        if (is_string($cached)) {
            return $cached;
        }

        try {
            $response = Craft::createGuzzleClient(['timeout' => 5, 'allow_redirects' => false])->get($url);
            $pem = (string)$response->getBody();
        } catch (\Throwable $e) {
            Craft::warning('Dispatch: SNS certificate fetch failed: ' . $e->getMessage(), __METHOD__);

            return null;
        }

        if (!str_contains($pem, 'BEGIN CERTIFICATE')) {
            return null;
        }

        Craft::$app->getCache()->set($cacheKey, $pem, 86400);

        return $pem;
    }

    private function fail(string $reason): bool
    {
        $this->lastError = $reason;

        return false;
    }
}
