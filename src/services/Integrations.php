<?php

namespace justinholtweb\dispatch\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use DateTime;
use GuzzleHttp\Client;
use justinholtweb\dispatch\elements\Campaign;
use justinholtweb\dispatch\elements\MailingList;
use justinholtweb\dispatch\elements\Subscriber;
use justinholtweb\dispatch\events\IntegrationEvent;
use justinholtweb\dispatch\helpers\Ip;
use justinholtweb\dispatch\models\Edition;
use justinholtweb\dispatch\models\Endpoint;
use justinholtweb\dispatch\Plugin;
use justinholtweb\dispatch\queue\jobs\DeliverWebhookJob;

/**
 * Outgoing webhooks (Pro, "Integrations"): tell other systems — a CRM, Zapier, a data warehouse —
 * when something happens in Dispatch.
 *
 * Not to be confused with the *incoming* bounce webhooks (`WebhookController`,
 * `WebhookVerifier`); those receive, these send.
 *
 * ## Delivery
 *
 * Each event is written to the delivery log once per subscribed endpoint, then POSTed by a queue
 * job. A failed attempt (network error or non-2xx answer) is retried with backoff — 1 minute,
 * 5 minutes, 30 minutes, 2 hours — then given up and left in the log as failed, where an admin can
 * redeliver it. The job never throws, so the queue's own retries can't double a delivery.
 *
 * ## Signature
 *
 * Every request carries `X-Dispatch-Signature: t=<unix time>,v1=<hex HMAC-SHA256>` computed over
 * `"<t>.<raw body>"` with the endpoint's secret, plus `X-Dispatch-Event` and `X-Dispatch-Delivery`
 * (unique per delivery, the same across its retries — use it to deduplicate). Receivers should
 * recompute the HMAC, compare in constant time and reject old timestamps.
 *
 * ## Where requests may go
 *
 * The URL is typed into the control panel, so delivery is guarded against SSRF exactly as Control
 * Tower's webhooks are: http(s) only, no credentials in the URL, every address the host resolves
 * to must be public ({@see Ip}), the connection is pinned to those addresses so DNS can't be
 * rebound between the check and the request, and redirects are not followed. A site that must post
 * to an internal service sets `allowPrivateIntegrationHosts` in `config/dispatch.php`.
 */
class Integrations extends Component
{
    /** @event IntegrationEvent Before an event is queued for its endpoints. */
    public const EVENT_BEFORE_DISPATCH = 'beforeDispatch';

    public const MAX_ATTEMPTS = 5;

    /** Seconds to wait before attempt 2, 3, 4 and 5. */
    public const BACKOFF = [60, 300, 1800, 7200];

    public const SIGNATURE_HEADER = 'X-Dispatch-Signature';

    private const ENDPOINTS = '{{%dispatch_endpoints}}';
    private const DELIVERIES = '{{%dispatch_deliveries}}';

    /**
     * Resolves a host to the addresses it may be connected to. Swappable for tests (the harness
     * has no DNS for the outside world); defaults to {@see Ip::resolvePublic()}.
     *
     * @var (\Closure(string): string[])|null
     */
    public ?\Closure $resolver = null;

    /**
     * Builds the HTTP client. Swappable for tests (a Guzzle MockHandler).
     *
     * @var (\Closure(): Client)|null
     */
    public ?\Closure $clientFactory = null;

    /** @var Endpoint[]|null Memoised for the request: one opened pixel shouldn't query this twice. */
    private ?array $_enabledEndpoints = null;

    // -------------------------------------------------------------------------------------------
    // Endpoints
    // -------------------------------------------------------------------------------------------

    /** @return Endpoint[] */
    public function getAllEndpoints(): array
    {
        return array_map(fn(array $row) => $this->_endpoint($row), $this->_endpointQuery()->orderBy(['name' => SORT_ASC])->all());
    }

    public function getEndpointById(int $id): ?Endpoint
    {
        $row = $this->_endpointQuery()->where(['id' => $id])->one();

        return $row ? $this->_endpoint($row) : null;
    }

    public function saveEndpoint(Endpoint $endpoint): bool
    {
        if (!$endpoint->validate()) {
            return false;
        }

        $data = [
            'name' => $endpoint->name,
            'url' => $endpoint->url,
            'secret' => $endpoint->secret,
            'events' => Json::encode(array_values($endpoint->events)),
            'enabled' => $endpoint->enabled,
        ];

        if ($endpoint->id) {
            Db::update(self::ENDPOINTS, $data, ['id' => $endpoint->id]);
        } else {
            $data['uid'] = StringHelper::UUID();
            Db::insert(self::ENDPOINTS, $data);
            $endpoint->id = (int)Craft::$app->getDb()->getLastInsertID();
            $endpoint->uid = $data['uid'];
        }

        $this->_enabledEndpoints = null;

        return true;
    }

    public function deleteEndpoint(int $id): bool
    {
        $this->_enabledEndpoints = null;

        return Db::delete(self::ENDPOINTS, ['id' => $id]) > 0;
    }

    /** A fresh signing secret for a new endpoint. */
    public static function generateSecret(): string
    {
        return 'whsec_' . bin2hex(random_bytes(24));
    }

    // -------------------------------------------------------------------------------------------
    // Events
    // -------------------------------------------------------------------------------------------

    /**
     * Queues an event for every enabled endpoint that wants it.
     *
     * @param array<string, mixed> $data
     * @return int Deliveries queued.
     */
    public function dispatch(string $name, array $data): int
    {
        if (!Edition::isPro()) {
            return 0;
        }

        $endpoints = array_filter($this->_getEnabledEndpoints(), static fn(Endpoint $e) => in_array($name, $e->events, true));

        if ($endpoints === []) {
            return 0;
        }

        $event = new IntegrationEvent(['webhookEvent' => $name, 'payload' => $data]);
        $this->trigger(self::EVENT_BEFORE_DISPATCH, $event);

        if (!$event->isValid) {
            return 0;
        }

        $queued = 0;

        foreach ($endpoints as $endpoint) {
            $this->queueDelivery($endpoint, $name, $event->payload);
            $queued++;
        }

        return $queued;
    }

    /**
     * Logs a delivery and queues its first attempt.
     *
     * @param array<string, mixed> $data
     */
    public function queueDelivery(Endpoint $endpoint, string $name, array $data): int
    {
        $deliveryUid = StringHelper::UUID();

        $body = Json::encode([
            'id' => $deliveryUid,
            'event' => $name,
            'createdAt' => DateTimeHelper::toIso8601(new DateTime()),
            'data' => $data,
        ]);

        Db::insert(self::DELIVERIES, [
            'endpointId' => $endpoint->id,
            'event' => $name,
            'payload' => $body,
            'status' => 'pending',
            'attempts' => 0,
            'uid' => $deliveryUid,
        ]);

        $id = (int)Craft::$app->getDb()->getLastInsertID();
        Craft::$app->getQueue()->push(new DeliverWebhookJob(['deliveryId' => $id]));

        return $id;
    }

    /**
     * Makes one delivery attempt. Only a pending delivery is attempted, and it is claimed first
     * (pending → delivering), so overlapping jobs can't POST it twice.
     *
     * @return bool Whether the endpoint accepted it.
     */
    public function deliver(int $deliveryId): bool
    {
        $claimed = Db::update(self::DELIVERIES, ['status' => 'delivering'], ['id' => $deliveryId, 'status' => 'pending']);

        if ($claimed !== 1) {
            return false;
        }

        $delivery = (new Query())->from(self::DELIVERIES)->where(['id' => $deliveryId])->one();
        $endpoint = $delivery ? $this->getEndpointById((int)$delivery['endpointId']) : null;

        if (!$delivery || !$endpoint) {
            return false;
        }

        $attempt = (int)$delivery['attempts'] + 1;

        if (!$endpoint->enabled) {
            $this->_finish($deliveryId, $attempt, 'failed', null, null, 'The endpoint is disabled.');
            return false;
        }

        $result = $this->post($endpoint, (string)$delivery['event'], (string)$delivery['uid'], (string)$delivery['payload']);

        if ($result['ok']) {
            $this->_finish($deliveryId, $attempt, 'success', $result['status'], $result['body'], null);
            return true;
        }

        // A URL the guard refuses won't become acceptable by waiting.
        $retry = !$result['refused'] && $attempt < self::MAX_ATTEMPTS;

        $this->_finish($deliveryId, $attempt, $retry ? 'pending' : 'failed', $result['status'], $result['body'], $result['error']);

        if ($retry) {
            $delay = self::BACKOFF[$attempt - 1] ?? self::BACKOFF[count(self::BACKOFF) - 1];
            Craft::$app->getQueue()->delay($delay)->push(new DeliverWebhookJob(['deliveryId' => $deliveryId]));
        }

        return false;
    }

    /**
     * Sends a failed (or stuck) delivery again from scratch: same body and delivery ID, attempts
     * reset.
     */
    public function redeliver(int $deliveryId): bool
    {
        $reset = Db::update(self::DELIVERIES, ['status' => 'pending', 'attempts' => 0, 'error' => null], [
            'id' => $deliveryId,
            'status' => ['failed', 'delivering'],
        ]);

        if ($reset !== 1) {
            return false;
        }

        Craft::$app->getQueue()->push(new DeliverWebhookJob(['deliveryId' => $deliveryId]));

        return true;
    }

    /**
     * POSTs a test `ping` to an endpoint right away, logged like any delivery.
     *
     * @return array{ok: bool, status: int|null, body: string, error: string|null, refused: bool}
     */
    public function sendTest(Endpoint $endpoint): array
    {
        $uid = StringHelper::UUID();
        $body = Json::encode([
            'id' => $uid,
            'event' => 'ping',
            'createdAt' => DateTimeHelper::toIso8601(new DateTime()),
            'data' => ['message' => 'A test from Dispatch. If you can read this, the endpoint works.'],
        ]);

        $result = $this->post($endpoint, 'ping', $uid, $body);

        Db::insert(self::DELIVERIES, [
            'endpointId' => $endpoint->id,
            'event' => 'ping',
            'payload' => $body,
            'status' => $result['ok'] ? 'success' : 'failed',
            'attempts' => 1,
            'responseCode' => $result['status'],
            'responseBody' => $this->_trim($result['body']),
            'error' => $result['error'],
            'deliveredAt' => $result['ok'] ? Scheduler::dbNow() : null,
            'uid' => $uid,
        ]);

        return $result;
    }

    /**
     * The signature header value for a body.
     */
    public static function sign(string $secret, string $body, int $timestamp): string
    {
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /**
     * POSTs a body to an endpoint, through the SSRF guard.
     *
     * @return array{ok: bool, status: int|null, body: string, error: string|null, refused: bool}
     */
    public function post(Endpoint $endpoint, string $event, string $deliveryUid, string $body): array
    {
        $url = $endpoint->resolvedUrl();
        $target = $this->target($url);

        if (is_string($target)) {
            Craft::warning("Dispatch refused to deliver a webhook to {$url}: {$target}", 'dispatch');

            return ['ok' => false, 'status' => null, 'body' => '', 'error' => $target, 'refused' => true];
        }

        $secret = $endpoint->resolvedSecret();

        if ($secret === '') {
            return ['ok' => false, 'status' => null, 'body' => '', 'error' => 'The endpoint has no signing secret.', 'refused' => true];
        }

        $options = [
            'body' => $body,
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'Dispatch-Webhooks/1.0 (Craft CMS)',
                'X-Dispatch-Event' => $event,
                'X-Dispatch-Delivery' => $deliveryUid,
                self::SIGNATURE_HEADER => self::sign($secret, $body, time()),
            ],
            'http_errors' => false,
            'allow_redirects' => false,
            'timeout' => 10,
            'connect_timeout' => 5,
        ];

        if ($target['addresses'] !== []) {
            $options['curl'] = [
                CURLOPT_RESOLVE => array_map(
                    static fn(string $ip) => sprintf('%s:%d:%s', $target['host'], $target['port'], str_contains($ip, ':') ? "[{$ip}]" : $ip),
                    $target['addresses'],
                ),
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            ];
        }

        try {
            $client = $this->clientFactory ? ($this->clientFactory)() : Craft::createGuzzleClient();
            $response = $client->post($url, $options);
            $status = $response->getStatusCode();
            $ok = $status >= 200 && $status < 300;

            return [
                'ok' => $ok,
                'status' => $status,
                'body' => (string)$response->getBody(),
                'error' => $ok ? null : "HTTP {$status}",
                'refused' => false,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => null, 'body' => '', 'error' => $e->getMessage(), 'refused' => false];
        }
    }

    /**
     * Where a webhook URL may be sent, or why it may not.
     *
     * @return array{host: string, port: int, addresses: string[]}|string The pinned target, or the refusal.
     */
    public function target(string $url): array|string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = (string)($parts['host'] ?? '');

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return 'Only http:// and https:// URLs are allowed.';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'The URL may not carry a username or password.';
        }

        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (Plugin::getInstance()->getSettings()->allowPrivateIntegrationHosts) {
            return ['host' => $host, 'port' => $port, 'addresses' => []];
        }

        $host = trim($host, '[]');
        $addresses = $this->resolver ? ($this->resolver)($host) : Ip::resolvePublic($host);

        // A test resolver gets the same scrutiny as DNS: every address must be public.
        if ($addresses === [] || array_filter($addresses, static fn(string $ip) => !Ip::isPublic($ip)) !== []) {
            return 'The host does not resolve to a public address.';
        }

        return ['host' => $host, 'port' => $port, 'addresses' => $addresses];
    }

    // -------------------------------------------------------------------------------------------
    // Delivery log
    // -------------------------------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> The most recent deliveries to an endpoint. */
    public function getDeliveries(int $endpointId, int $limit = 50): array
    {
        return (new Query())
            ->select(['id', 'event', 'status', 'attempts', 'responseCode', 'responseBody', 'error', 'deliveredAt', 'dateCreated', 'uid'])
            ->from(self::DELIVERIES)
            ->where(['endpointId' => $endpointId])
            ->orderBy(['id' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    /** Deletes delivery log entries older than `$days` (run from Craft's garbage collection). */
    public function pruneDeliveries(int $days = 30): int
    {
        return Db::delete(self::DELIVERIES, ['<', 'dateCreated', Db::prepareDateForDb(new DateTime("-{$days} days"))]);
    }

    // -------------------------------------------------------------------------------------------
    // Payload shapes
    // -------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public static function subscriberData(?Subscriber $subscriber): ?array
    {
        if (!$subscriber) {
            return null;
        }

        return [
            'id' => (int)$subscriber->id,
            'email' => $subscriber->email,
            'firstName' => $subscriber->firstName,
            'lastName' => $subscriber->lastName,
            'status' => $subscriber->status,
        ];
    }

    /** @return array<string, mixed>|null */
    public static function listData(?MailingList $list): ?array
    {
        return $list ? ['id' => (int)$list->id, 'handle' => $list->handle, 'name' => $list->title] : null;
    }

    /** @return array<string, mixed>|null */
    public static function campaignData(?Campaign $campaign): ?array
    {
        if (!$campaign) {
            return null;
        }

        return [
            'id' => (int)$campaign->id,
            'title' => $campaign->title,
            'subject' => $campaign->subject,
            'status' => $campaign->campaignStatus,
            'totalRecipients' => $campaign->totalRecipients,
            'totalSent' => $campaign->totalSent,
            'totalFailed' => $campaign->totalFailed,
            'sentAt' => $campaign->sentAt ? DateTimeHelper::toIso8601(DateTimeHelper::toDateTime($campaign->sentAt)) : null,
        ];
    }

    private function _finish(int $id, int $attempts, string $status, ?int $code, ?string $body, ?string $error): void
    {
        Db::update(self::DELIVERIES, [
            'status' => $status,
            'attempts' => $attempts,
            'responseCode' => $code,
            'responseBody' => $this->_trim($body),
            'error' => $error ? mb_substr($error, 0, 1000) : null,
            'deliveredAt' => $status === 'success' ? Scheduler::dbNow() : null,
        ], ['id' => $id]);
    }

    private function _trim(?string $body): ?string
    {
        return $body === null || $body === '' ? null : mb_substr($body, 0, 1000);
    }

    /** @return Endpoint[] */
    private function _getEnabledEndpoints(): array
    {
        return $this->_enabledEndpoints ??= array_map(
            fn(array $row) => $this->_endpoint($row),
            $this->_endpointQuery()->where(['enabled' => true])->all(),
        );
    }

    private function _endpointQuery(): Query
    {
        return (new Query())
            ->select(['id', 'name', 'url', 'secret', 'events', 'enabled', 'uid'])
            ->from(self::ENDPOINTS);
    }

    private function _endpoint(array $row): Endpoint
    {
        $events = Json::decodeIfJson((string)$row['events']);
        $row['events'] = is_array($events) ? array_values(array_map('strval', $events)) : [];
        $row['enabled'] = (bool)$row['enabled'];

        return new Endpoint($row);
    }
}
