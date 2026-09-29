<?php

namespace justinholtweb\dispatch\helpers;

use Craft;
use craft\helpers\UrlHelper;

/**
 * Signed links in campaign email: open pixel, click tracking, unsubscribe and preferences.
 *
 * ## The query parameter is `dtoken`, never `token`
 *
 * `token` is Craft's own parameter (preview and share tokens). Craft validates it before any
 * controller runs and answers anything it doesn't recognise with "400 Invalid token" — so every
 * link Dispatch signed with `?token=` (unsubscribe, one-click List-Unsubscribe, the open pixel,
 * every tracked link) failed. Links already sitting in inboxes are rescued by
 * {@see translateLegacyTokenParam()}, which runs while the plugin loads, before Craft looks.
 *
 * ## Tokens are bound to their purpose
 *
 * Each HMAC covers `<purpose>:<ids…>`. Without the purpose, the open-pixel token H(campaign:subscriber)
 * *was* the unsubscribe token H(subscriber:list) for a different pair of IDs, so a recipient could
 * unsubscribe other people.
 *
 * Tokens minted before 5.1 (no purpose, sent as `token`) are still accepted for opens, clicks and
 * unsubscribing — people must be able to unsubscribe from mail they already have — but not for
 * the preferences page, which can subscribe somebody to lists.
 */
class TrackingHelper
{
    public const PARAM = 'dtoken';

    /** Set on a request whose token arrived as a legacy `?token=`. */
    public const LEGACY_FLAG = 'dtokenLegacy';

    public const PURPOSE_OPEN = 'open';
    public const PURPOSE_CLICK = 'click';
    public const PURPOSE_UNSUBSCRIBE = 'unsub';
    public const PURPOSE_PREFERENCES = 'prefs';

    private const HMAC_ALGO = 'sha256';

    /** Purposes whose pre-5.1 tokens are still honoured. */
    private const LEGACY_PURPOSES = [self::PURPOSE_OPEN, self::PURPOSE_CLICK, self::PURPOSE_UNSUBSCRIBE];

    public static function sign(string $purpose, int|string ...$parts): string
    {
        return hash_hmac(self::HMAC_ALGO, $purpose . ':' . implode(':', $parts), self::_getSecret());
    }

    /**
     * Whether a token is valid for a purpose and set of IDs. A legacy (purpose-less) token is
     * accepted only for the purposes that must keep working for mail already sent.
     */
    public static function verify(string $token, string $purpose, int|string ...$parts): bool
    {
        if ($token === '') {
            return false;
        }

        if (hash_equals(self::sign($purpose, ...$parts), $token)) {
            return true;
        }

        return in_array($purpose, self::LEGACY_PURPOSES, true)
            && hash_equals(self::legacySign(...$parts), $token);
    }

    /** The token from the current request: `dtoken`, in the body or the query string. */
    public static function requestToken(): string
    {
        $request = Craft::$app->getRequest();

        return (string)($request->getBodyParam(self::PARAM) ?? $request->getQueryParam(self::PARAM) ?? '');
    }

    /**
     * Rescues pre-5.1 links: renames a Dispatch-signed `?token=` to `?dtoken=` before Craft's
     * token check sees it.
     *
     * Only a 64-character hex value on a request that also carries `sid` is touched — that is the
     * shape of a Dispatch HMAC, and Craft's own tokens (32 URL-safe characters) never match it.
     * Must run during plugin init: Craft checks the token right after plugins load.
     */
    public static function translateLegacyTokenParam(): void
    {
        $request = Craft::$app->getRequest();

        if (!$request instanceof \craft\web\Request) {
            return;
        }

        $params = $request->getQueryParams();
        $token = $params['token'] ?? null;

        if (!is_string($token) || !isset($params['sid']) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return;
        }

        unset($params['token']);
        $params[self::PARAM] = $token;
        $params[self::LEGACY_FLAG] = '1';
        $request->setQueryParams($params);

        // Craft may already have looked for a token before plugins loaded, and it memoises the
        // verdict in private properties. With the value renamed there is no Craft token on this
        // request, so set that state directly — what Craft would compute for a request without
        // one. (Not unset(): an unset declared property routes later writes through __set(),
        // which Yii rejects.) Guarded: if Craft's internals change, the worst case is the old
        // behaviour — a 400 on a pre-5.1 link — never a new failure.
        try {
            (function(): void {
                $this->_hasInvalidToken = false;
                $this->_hadToken = false;
                $this->_token = null;
                $this->_tokenRoute = null;
            })->call($request);
        } catch (\Throwable) {
        }
    }

    public static function unsubscribeUrl(int $subscriberId, int $listId, ?string $baseUrl = null): string
    {
        $params = [
            'sid' => $subscriberId,
            'lid' => $listId,
            self::PARAM => self::sign(self::PURPOSE_UNSUBSCRIBE, $subscriberId, $listId),
        ];

        return $baseUrl
            ? $baseUrl . (str_contains($baseUrl, '?') ? '&' : '?') . http_build_query($params)
            : UrlHelper::siteUrl('dispatch/unsubscribe', $params);
    }

    public static function preferencesUrl(int $subscriberId): string
    {
        return UrlHelper::siteUrl('dispatch/preferences', [
            'sid' => $subscriberId,
            self::PARAM => self::sign(self::PURPOSE_PREFERENCES, $subscriberId),
        ]);
    }

    public static function injectTrackingPixel(string $html, int $campaignId, int $subscriberId): string
    {
        $pixelUrl = UrlHelper::siteUrl('dispatch/track/open', [
            'cid' => $campaignId,
            'sid' => $subscriberId,
            self::PARAM => self::sign(self::PURPOSE_OPEN, $campaignId, $subscriberId),
        ]);

        $pixel = '<img src="' . htmlspecialchars($pixelUrl) . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;" />';

        // Insert before </body> if present, otherwise append
        if (stripos($html, '</body>') !== false) {
            $html = str_ireplace('</body>', $pixel . '</body>', $html);
        } else {
            $html .= $pixel;
        }

        return $html;
    }

    public static function rewriteLinks(string $html, int $campaignId, int $subscriberId): string
    {
        return preg_replace_callback(
            '/<a\s([^>]*?)href="([^"]+)"([^>]*?)>/i',
            function($match) use ($campaignId, $subscriberId) {
                $url = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5);

                // Skip anchors, mailto, tel, and Dispatch's own links
                if (str_starts_with($url, '#') ||
                    str_starts_with($url, 'mailto:') ||
                    str_starts_with($url, 'tel:') ||
                    str_contains($url, 'dispatch/unsubscribe') ||
                    str_contains($url, 'dispatch/preferences') ||
                    str_contains($url, 'dispatch/track/')) {
                    return $match[0];
                }

                $trackUrl = UrlHelper::siteUrl('dispatch/track/click', [
                    'cid' => $campaignId,
                    'sid' => $subscriberId,
                    'url' => $url,
                    self::PARAM => self::sign(self::PURPOSE_CLICK, $campaignId, $subscriberId, $url),
                ]);

                return '<a ' . $match[1] . 'href="' . htmlspecialchars($trackUrl) . '"' . $match[3] . '>';
            },
            $html
        );
    }

    /** The pre-5.1 scheme: HMAC of the IDs alone, no purpose. */
    private static function legacySign(int|string ...$parts): string
    {
        return hash_hmac(self::HMAC_ALGO, implode(':', $parts), self::_getSecret());
    }

    private static function _getSecret(): string
    {
        return Craft::$app->getConfig()->getGeneral()->securityKey . ':dispatch';
    }
}
