<?php

namespace justinholtweb\dispatch\services;

use Craft;
use craft\base\Component;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use DateInterval;
use DateTime;
use justinholtweb\dispatch\elements\MailingList;
use justinholtweb\dispatch\elements\Subscriber;
use justinholtweb\dispatch\events\SignupEvent;
use justinholtweb\dispatch\events\SubscriberEvent;
use justinholtweb\dispatch\helpers\ConsentBridge;
use justinholtweb\dispatch\helpers\Optin;
use justinholtweb\dispatch\Plugin;
use justinholtweb\dispatch\records\PendingSignupRecord;

/**
 * Front-end sign-ups, with double opt-in.
 *
 * With double opt-in on (the default) a sign-up is a row in `dispatch_pendingsignups` and a
 * confirmation email — not a subscriber. Only when the link in that email is used does the
 * address become a subscriber and join its lists; that is when drip sequences start and the
 * `subscriber.subscribed` webhook fires. Unused sign-ups are deleted when they expire.
 *
 * Whatever happens to a sign-up, the visitor is told the same thing: an address that is already
 * subscribed, has bounced, or was signed up a minute ago gets the same answer as a new one, so
 * the form can't be used to find out who is on the list.
 */
class Signups extends Component
{
    /** @event SignupEvent Before a sign-up is acted on. Set `$isValid` false to drop it silently. */
    public const EVENT_BEFORE_SIGNUP = 'beforeSignup';

    /** @event SignupEvent After a double opt-in sign-up is confirmed; `$subscriber` is set. */
    public const EVENT_AFTER_CONFIRM = 'afterConfirm';

    /** A confirmation email went out; the sign-up waits for its link. */
    public const OUTCOME_PENDING = 'pending';
    /** Single opt-in: subscribed straight away. */
    public const OUTCOME_SUBSCRIBED = 'subscribed';
    /** Already an active subscriber on every list asked for: nothing to do. */
    public const OUTCOME_ALREADY = 'already';
    /** A confirmation was sent to this address moments ago, or too often today: not resent. */
    public const OUTCOME_THROTTLED = 'throttled';
    /** Bounced or complained (or, without double opt-in, unsubscribed): not mailed, not changed. */
    public const OUTCOME_SUPPRESSED = 'suppressed';
    /** Dropped by an `EVENT_BEFORE_SIGNUP` handler. */
    public const OUTCOME_VETOED = 'vetoed';

    /** A new confirmation for the same address is sent at most this often. */
    public const RESEND_AFTER_MINUTES = 5;

    /** And at most this many times a day — the form must not be a way to mail-bomb someone. */
    public const MAX_CONFIRMATIONS_PER_DAY = 5;

    /** Form config keys a signed `signup` value may carry. */
    private const CONFIG_KEYS = ['lists', 'consentText', 'source', 'chooseLists'];

    /**
     * The outcome of the last {@see start()} in this request, and the token of the last
     * confirmation email it sent. For tests and for code that sends its own email; the visitor
     * is never told either. Never stored.
     */
    public ?string $lastOutcome = null;
    public ?string $lastToken = null;

    // Form config
    // -------------------------------------------------------------------------

    /**
     * A sign-up form's configuration, signed so the visitor can't change it: which lists the form
     * signs people up to, the consent wording its checkbox shows (blank for no checkbox), a label
     * for where the sign-up came from, and whether the visitor picks among the lists.
     *
     * Signing is what keeps an anonymous form from joining anybody to any list: the template
     * author chooses the lists, and a posted value that isn't one they signed is refused.
     *
     * @param array<string, mixed> $config
     */
    public function signConfig(array $config): string
    {
        return Craft::$app->getSecurity()->hashData(Json::encode($this->normalizeConfig($config)));
    }

    /**
     * A posted `signup` value, verified. Null when it is missing, tampered with, or signed with
     * another site's key.
     *
     * @return array{lists: string[], consentText: string, source: string, chooseLists: bool}|null
     */
    public function readConfig(mixed $signed): ?array
    {
        if (!is_string($signed) || $signed === '') {
            return null;
        }

        $json = Craft::$app->getSecurity()->validateData($signed);
        $config = is_string($json) ? Json::decodeIfJson($json) : null;

        return is_array($config) ? $this->normalizeConfig($config) : null;
    }

    /**
     * @param array<string, mixed> $config
     * @return array{lists: string[], consentText: string, source: string, chooseLists: bool}
     */
    public function normalizeConfig(array $config): array
    {
        $config = array_intersect_key($config, array_flip(self::CONFIG_KEYS));
        $lists = $config['lists'] ?? [];
        $lists = is_string($lists) ? explode(',', $lists) : (is_array($lists) ? $lists : []);
        $lists = array_map(static fn($l) => $l instanceof MailingList ? (string)$l->handle : (is_scalar($l) ? trim((string)$l) : ''), $lists);
        $text = Optin::normalizeText(is_scalar($config['consentText'] ?? null) ? (string)$config['consentText'] : '');

        return [
            'lists' => array_values(array_unique(array_filter($lists, static fn($l) => $l !== ''))),
            'consentText' => mb_substr($text, 0, Optin::MAX_TEXT),
            'source' => mb_substr(trim(is_scalar($config['source'] ?? null) ? (string)$config['source'] : ''), 0, 100),
            'chooseLists' => Optin::ticked($config['chooseLists'] ?? false),
        ];
    }

    /**
     * The lists matching handles or IDs. Unknown ones are left out.
     *
     * @param array<int, string|int> $handlesOrIds
     * @return MailingList[]
     */
    public function resolveLists(array $handlesOrIds): array
    {
        $handles = array_values(array_filter($handlesOrIds, static fn($v) => is_string($v) && !ctype_digit($v)));
        $ids = array_values(array_map('intval', array_filter($handlesOrIds, static fn($v) => is_int($v) || ctype_digit($v))));
        $lists = [];

        if ($handles !== []) {
            // Exact match: `handle` is a Db::parseParam column, where `*` and `not ` are operators.
            foreach (MailingList::find()->andWhere(['dispatch_mailinglists.handle' => $handles])->all() as $list) {
                $lists[$list->id] = $list;
            }
        }

        if ($ids !== []) {
            foreach (MailingList::find()->id($ids)->all() as $list) {
                $lists[$list->id] = $list;
            }
        }

        return array_values($lists);
    }

    // Signing up
    // -------------------------------------------------------------------------

    /**
     * Signs an address up to lists. Returns one of the `OUTCOME_*` constants — for the caller's
     * logs and tests only; a visitor must be given the same answer whichever it is.
     *
     * `$details`: `firstName`, `lastName`, `source` (where the sign-up came from),
     * `consentText` (the wording the visitor ticked, already checked by the caller), `pageUrl`,
     * `siteId`. The visitor's IP (as a keyed hash) and, when Toss manages consent, their Toss
     * answer are read from the current request.
     *
     * @param int[] $listIds
     * @param array<string, mixed> $details
     */
    public function start(string $email, array $listIds, array $details = []): string
    {
        $this->lastToken = null;
        $email = trim($email);
        $listIds = array_values(array_unique(array_map('intval', $listIds)));

        if ($this->hasEventHandlers(self::EVENT_BEFORE_SIGNUP)) {
            $event = new SignupEvent(['email' => $email, 'listIds' => $listIds, 'details' => $details]);
            $this->trigger(self::EVENT_BEFORE_SIGNUP, $event);

            if (!$event->isValid) {
                return $this->lastOutcome = self::OUTCOME_VETOED;
            }
        }

        $subscriber = Plugin::getInstance()->subscribers->getByEmail($email);

        if ($subscriber !== null && in_array($subscriber->status, ['bounced', 'complained'], true)) {
            return $this->lastOutcome = self::OUTCOME_SUPPRESSED;
        }

        $evidence = $this->evidence($details, $listIds);
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->doubleOptIn) {
            return $this->lastOutcome = $this->subscribeNow($subscriber, $email, $listIds, $details, $evidence);
        }

        if ($subscriber !== null && $subscriber->status === 'active' && array_diff($listIds, $this->listIdsOf((int)$subscriber->id)) === []) {
            return $this->lastOutcome = self::OUTCOME_ALREADY;
        }

        return $this->lastOutcome = $this->createPending($email, $listIds, $details, $evidence);
    }

    /**
     * Single opt-in. A new address is subscribed; an active one joins the lists. An address that
     * unsubscribed is left alone: without a confirmation, the form can't tell them from somebody
     * typing their address, and re-subscribing people who left is what double opt-in prevents.
     *
     * @param int[] $listIds
     * @param array<string, mixed> $details
     * @param array<string, mixed> $evidence
     */
    private function subscribeNow(?Subscriber $subscriber, string $email, array $listIds, array $details, array $evidence): string
    {
        if ($subscriber !== null && $subscriber->status !== 'active') {
            return self::OUTCOME_SUPPRESSED;
        }

        $subscriber ??= new Subscriber(['email' => $email, 'status' => 'active']);
        $this->fillNames($subscriber, $details);
        $subscriber->consentedAt = Db::prepareDateForDb(new DateTime());
        $subscriber->consent = ['method' => 'single-opt-in'] + $evidence;

        if (!Plugin::getInstance()->subscribers->create($subscriber)) {
            Craft::warning('Dispatch: a sign-up could not be saved: ' . Json::encode($subscriber->getErrors()), 'dispatch');

            return self::OUTCOME_SUPPRESSED;
        }

        foreach ($listIds as $listId) {
            Plugin::getInstance()->subscribers->subscribe((int)$subscriber->id, $listId);
        }

        return self::OUTCOME_SUBSCRIBED;
    }

    /**
     * Stores (or refreshes) the address's pending sign-up and emails its confirmation link.
     *
     * One pending sign-up per address: signing up again replaces the token — the older link
     * stops working — and adds the new lists to the ones still waiting. A new link goes out at
     * most every few minutes, and a handful of times a day.
     *
     * @param int[] $listIds
     * @param array<string, mixed> $details
     * @param array<string, mixed> $evidence
     */
    private function createPending(string $email, array $listIds, array $details, array $evidence): string
    {
        $key = mb_strtolower($email);
        $now = new DateTime();
        $record = PendingSignupRecord::find()->where(['email' => $key])->one();

        if ($record instanceof PendingSignupRecord && $record->dateUpdated > Db::prepareDateForDb((clone $now)->modify('-' . self::RESEND_AFTER_MINUTES . ' minutes'))) {
            return self::OUTCOME_THROTTLED;
        }

        if (!$this->allowConfirmationTo($key)) {
            return self::OUTCOME_THROTTLED;
        }

        $token = Optin::newToken();
        $hours = max(1, Plugin::getInstance()->getSettings()->confirmationExpiryHours);
        $nowDb = Db::prepareDateForDb($now);

        if ($record instanceof PendingSignupRecord) {
            $stillValid = $record->expiresAt > $nowDb;
            $previous = $stillValid ? $this->decodeIds($record->listIds) : [];
            $listIds = array_values(array_unique(array_merge($previous, $listIds)));
        } else {
            $record = new PendingSignupRecord();
            $record->email = $key;
        }

        $record->firstName = $this->cleanName($details['firstName'] ?? null) ?? $record->firstName;
        $record->lastName = $this->cleanName($details['lastName'] ?? null) ?? $record->lastName;
        $record->listIds = $listIds;
        $record->tokenHash = Optin::hash($token);
        $record->expiresAt = Db::prepareDateForDb((clone $now)->add(new DateInterval("PT{$hours}H")));
        $record->consent = $evidence;
        $record->siteId = $this->siteId($details);

        try {
            if (!$record->save(false)) {
                return self::OUTCOME_THROTTLED;
            }
        } catch (\yii\db\IntegrityException) {
            // Two sign-ups for one address at the same moment: the other one won.
            return self::OUTCOME_THROTTLED;
        }

        $this->lastToken = $token;
        $this->sendConfirmation($record, $token);

        return self::OUTCOME_PENDING;
    }

    /**
     * Emails the confirmation link. The body is the plain-text setting with its placeholders
     * filled in — not Twig, so nothing a visitor typed is ever evaluated — and the HTML part is
     * that text escaped, with the link made a link. The visitor's name isn't used at all.
     */
    public function sendConfirmation(PendingSignupRecord $record, string $token): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        $defaults = new \justinholtweb\dispatch\models\Settings();
        $link = $this->confirmUrl($token, $record->siteId);
        $vars = [
            '{siteName}' => $this->siteName($record->siteId),
            '{lists}' => $this->listNames($this->decodeIds($record->listIds)),
            '{hours}' => (string)max(1, $settings->confirmationExpiryHours),
        ];

        $subject = strtr(trim($settings->confirmationSubject) ?: $defaults->confirmationSubject, $vars);
        $body = trim($settings->confirmationBody) ?: $defaults->confirmationBody;
        $text = strtr($body, $vars + ['{link}' => $link]);
        $html = nl2br(strtr(Html::encode($body), array_map([Html::class, 'encode'], $vars) + [
            '{link}' => Html::a(Html::encode($link), $link),
        ]), false);

        $fromName = $settings->defaultFromName ?: Craft::$app->getProjectConfig()->get('email.fromName') ?: Craft::$app->getSystemName();
        $fromEmail = $settings->defaultFromEmail ?: Craft::$app->getProjectConfig()->get('email.fromEmail');

        try {
            $message = Craft::$app->getMailer()->compose()
                ->setTo($record->email)
                ->setSubject($subject)
                ->setTextBody($text)
                ->setHtmlBody($html);

            if ($fromEmail) {
                $message->setFrom([$fromEmail => $fromName]);
            }

            if ($settings->defaultReplyToEmail) {
                $message->setReplyTo($settings->defaultReplyToEmail);
            }

            $sent = $message->send();
        } catch (\Throwable $e) {
            Craft::error("Dispatch couldn’t send the confirmation email for pending sign-up {$record->id}: {$e->getMessage()}", 'dispatch');

            return false;
        }

        if (!$sent) {
            Craft::error("Dispatch couldn’t send the confirmation email for pending sign-up {$record->id}.", 'dispatch');
        }

        return $sent;
    }

    /** The page a confirmation link opens, on the site the sign-up came from. */
    public function confirmUrl(string $token, ?int $siteId = null): string
    {
        // `code`, not `token`: Craft reads a `token` query param as its own preview token.
        return UrlHelper::siteUrl('dispatch/confirm', ['code' => $token], null, $siteId);
    }

    // Confirming
    // -------------------------------------------------------------------------

    /**
     * The unexpired pending sign-up a token belongs to, or null. Read-only — showing the
     * confirmation page changes nothing, so a mail scanner that opens the link confirms nobody.
     *
     * An exact match on the token's hash, after the token's shape is checked: never a LIKE, never
     * `Db::parseParam()`, which would read `*` and `not ` in a token as operators.
     */
    public function findPending(mixed $token): ?PendingSignupRecord
    {
        if (!Optin::isTokenShaped($token)) {
            return null;
        }

        $record = PendingSignupRecord::find()
            ->where(['tokenHash' => Optin::hash($token)])
            ->andWhere(['>', 'expiresAt', Db::prepareDateForDb(new DateTime())])
            ->one();

        return $record instanceof PendingSignupRecord ? $record : null;
    }

    /**
     * Confirms the sign-up a token belongs to: once, and only before it expires. Returns the
     * subscriber, or null for a token that is malformed, unknown, used or expired — the caller
     * can't tell which, and neither can whoever is guessing.
     *
     * The pending row is spent by a single conditional DELETE, so two clicks racing each other
     * confirm once. Then the address becomes an active subscriber with its consent recorded,
     * joins its lists (starting sequences and webhooks), and — when Lock is installed — the
     * consent goes into Lock's ledger, marked verified.
     */
    public function confirm(mixed $token): ?Subscriber
    {
        $record = $this->findPending($token);

        if ($record === null) {
            return null;
        }

        $nowDate = new DateTime();
        $now = Db::prepareDateForDb($nowDate);
        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            $won = $db->createCommand()->delete(PendingSignupRecord::tableName(), [
                'and',
                ['id' => $record->id],
                ['tokenHash' => Optin::hash((string)$token)],
                ['>', 'expiresAt', $now],
            ])->execute();

            if ($won !== 1) {
                $transaction->rollBack();

                return null;
            }

            $subscribers = Plugin::getInstance()->subscribers;
            $subscriber = $subscribers->getByEmail($record->email) ?? new Subscriber(['email' => $record->email]);
            $wasActive = $subscriber->id && $subscriber->status === 'active';
            $subscriber->status = 'active';
            $subscriber->unsubscribedAt = null;
            $this->fillNames($subscriber, ['firstName' => $record->firstName, 'lastName' => $record->lastName]);

            $evidence = $this->decodeArray($record->consent);
            $subscriber->consentedAt = $now;
            $subscriber->consent = ['method' => 'double-opt-in'] + $evidence + ['confirmedAt' => $nowDate->format(DATE_ATOM)];

            if (!$subscribers->create($subscriber)) {
                $transaction->rollBack();
                Craft::warning('Dispatch: a confirmed sign-up could not be saved: ' . Json::encode($subscriber->getErrors()), 'dispatch');

                return null;
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            }

            throw $e;
        }

        $listIds = $this->decodeIds($record->listIds);

        foreach ($this->resolveLists($listIds) as $list) {
            $subscribers->subscribe((int)$subscriber->id, (int)$list->id);
        }

        $this->recordInLock($subscriber, $listIds);

        if ($this->hasEventHandlers(self::EVENT_AFTER_CONFIRM)) {
            $this->trigger(self::EVENT_AFTER_CONFIRM, new SignupEvent([
                'email' => (string)$subscriber->email,
                'listIds' => $listIds,
                'details' => ['firstName' => $record->firstName, 'lastName' => $record->lastName, 'wasActive' => $wasActive],
                'subscriber' => $subscriber,
            ]));
        }

        return $subscriber;
    }

    /**
     * Pending sign-ups whose link has expired. They were never confirmed, so they aren't kept:
     * removed when Craft collects garbage, and by this method. Returns how many went.
     */
    public function purgeExpired(): int
    {
        return Db::delete(PendingSignupRecord::tableName(), ['<=', 'expiresAt', Db::prepareDateForDb(new DateTime())]);
    }

    /** Sign-ups waiting for their confirmation (unexpired). */
    public function pendingCount(): int
    {
        return (int)PendingSignupRecord::find()->where(['>', 'expiresAt', Db::prepareDateForDb(new DateTime())])->count();
    }

    // Lock
    // -------------------------------------------------------------------------

    /**
     * A full unsubscribe of someone whose consent a sign-up form recorded is written to Lock's
     * ledger as a withdrawal. Leaving one list isn't: they still want the others.
     */
    public function handleUnsubscribe(SubscriberEvent $event): void
    {
        $subscriber = $event->subscriber;
        $purpose = trim(Plugin::getInstance()->getSettings()->lockPurpose);

        if ($event->mailingList !== null || $subscriber === null || !$subscriber->consentedAt || $purpose === '' || !ConsentBridge::lockIsActive()) {
            return;
        }

        ConsentBridge::withdrawInLock((string)$subscriber->email, $purpose, [
            'via' => 'dispatch',
            'method' => 'unsubscribe',
            'subscriberId' => $subscriber->id,
        ]);
    }

    /** @param int[] $listIds */
    private function recordInLock(Subscriber $subscriber, array $listIds): void
    {
        $purpose = trim(Plugin::getInstance()->getSettings()->lockPurpose);

        if ($purpose === '' || !ConsentBridge::lockIsActive()) {
            return;
        }

        $consent = $subscriber->consent ?? [];

        // Lock's own evidence keys (`text`, `url`) where they exist, so its anonymisation
        // treats them as it should. The address is only ever kept as a keyed hash here.
        ConsentBridge::recordInLock((string)$subscriber->email, $purpose, array_filter([
            'text' => $consent['text'] ?? null,
            'textVersion' => $consent['textVersion'] ?? null,
            'url' => $consent['pageUrl'] ?? null,
            'ipHash' => $consent['ipHash'] ?? null,
            'verified' => true,
            'method' => 'double opt-in',
            'via' => 'dispatch',
            'source' => $consent['source'] ?? null,
            'lists' => $this->listNames($listIds),
            'subscriberId' => $subscriber->id,
            'signedUpAt' => $consent['signedUpAt'] ?? null,
            'confirmedAt' => $consent['confirmedAt'] ?? null,
            'toss' => $consent['toss'] ?? null,
        ], static fn($value) => $value !== null && $value !== ''));
    }

    // Helpers
    // -------------------------------------------------------------------------

    /**
     * What is recorded about a sign-up's consent, read in the visitor's own request.
     *
     * @param array<string, mixed> $details
     * @param int[] $listIds
     * @return array<string, mixed>
     */
    private function evidence(array $details, array $listIds): array
    {
        $request = Craft::$app->getRequest();
        $web = $request instanceof \craft\web\Request;
        $text = Optin::normalizeText(is_scalar($details['consentText'] ?? null) ? (string)$details['consentText'] : '');
        $pageUrl = is_string($details['pageUrl'] ?? null) ? $details['pageUrl'] : ($web ? $request->getReferrer() : null);

        return array_filter([
            'source' => is_scalar($details['source'] ?? null) ? mb_substr(trim((string)$details['source']), 0, 100) : null,
            'text' => $text !== '' ? $text : null,
            'textVersion' => $text !== '' ? Optin::version($text) : null,
            'pageUrl' => is_string($pageUrl) && $pageUrl !== '' ? mb_substr($pageUrl, 0, 2048) : null,
            'ipHash' => $web ? Optin::ipHash((string)$request->getUserIP(), (string)Craft::$app->getConfig()->getGeneral()->securityKey) : null,
            'lists' => array_map(static fn(MailingList $l) => (string)$l->handle, $this->resolveLists($listIds)),
            'signedUpAt' => (new DateTime())->format(DATE_ATOM),
            'toss' => ConsentBridge::tossSnapshot(),
        ], static fn($value) => $value !== null && $value !== '' && $value !== []);
    }

    /** One more confirmation email to this address today? Counted per address per day. */
    private function allowConfirmationTo(string $email): bool
    {
        $key = 'dispatch:signup-confirmations:' . sha1($email) . ':' . date('Ymd');
        $cache = Craft::$app->getCache();
        $count = (int)$cache->get($key);

        if ($count >= self::MAX_CONFIRMATIONS_PER_DAY) {
            return false;
        }

        $cache->set($key, $count + 1, 86400);

        return true;
    }

    /** @param array<string, mixed> $details */
    private function fillNames(Subscriber $subscriber, array $details): void
    {
        // A sign-up form fills names in; it never overwrites ones already there.
        $subscriber->firstName = $subscriber->firstName ?: $this->cleanName($details['firstName'] ?? null);
        $subscriber->lastName = $subscriber->lastName ?: $this->cleanName($details['lastName'] ?? null);
    }

    private function cleanName(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');

        return $value !== '' ? mb_substr($value, 0, 255) : null;
    }

    /** @return int[] */
    private function listIdsOf(int $subscriberId): array
    {
        return array_map('intval', \justinholtweb\dispatch\records\SubscriptionRecord::find()
            ->select('mailingListId')
            ->where(['subscriberId' => $subscriberId])
            ->column());
    }

    /** @param int[] $listIds */
    private function listNames(array $listIds): string
    {
        $names = array_map(static fn(MailingList $l) => (string)($l->title ?: $l->name), $this->resolveLists($listIds));

        return $names !== [] ? implode(', ', $names) : $this->siteName(null);
    }

    /** @param array<string, mixed> $details */
    private function siteId(array $details): ?int
    {
        if (isset($details['siteId']) && is_numeric($details['siteId'])) {
            return (int)$details['siteId'];
        }

        return Craft::$app->getSites()->getCurrentSite()->id ?? null;
    }

    private function siteName(?int $siteId): string
    {
        $sites = Craft::$app->getSites();
        $site = $siteId ? $sites->getSiteById($siteId) : null;

        return ($site ?? $sites->getCurrentSite())->getName();
    }

    /** @return int[] */
    private function decodeIds(mixed $value): array
    {
        return array_values(array_map('intval', array_filter($this->decodeArray($value), 'is_numeric')));
    }

    /** MySQL hands a json column back as a string; MariaDB stores it as text. */
    private function decodeArray(mixed $value): array
    {
        $value = is_string($value) ? Json::decodeIfJson($value) : $value;

        return is_array($value) ? $value : [];
    }
}
