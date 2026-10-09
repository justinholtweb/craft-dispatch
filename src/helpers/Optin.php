<?php

namespace justinholtweb\dispatch\helpers;

/**
 * Double opt-in confirmation tokens and the consent wording's fingerprint.
 *
 * Pure (no Craft), so it is unit-tested on its own. The same token scheme as craft-leads: 32
 * random bytes, base64url; only the SHA-256 is stored, and a token's shape is checked before any
 * lookup, which is an exact match on that hash.
 */
abstract class Optin
{
    /** Longest consent wording kept; it's a sentence beside a checkbox, not a policy. */
    public const MAX_TEXT = 1000;

    /** A token is 32 random bytes, base64url without padding: always 43 characters. */
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    /**
     * Consent wording as HTML: everything escaped, then `[label](url)` turned into a link when the
     * URL is `http(s)://` or site-relative. Anything else, `javascript:` included, stays as text.
     */
    public static function html(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $linked = preg_replace_callback(
            '~\[([^\[\]\n]{1,200})\]\(((?:https?://|/(?!/))[^\s()<>"\'&]*(?:&amp;[^\s()<>"\'&]*)*)\)~i',
            static fn(array $m) => '<a href="' . $m[2] . '" target="_blank" rel="noopener">' . $m[1] . '</a>',
            $escaped,
        );

        return nl2br($linked ?? $escaped, false);
    }

    /** A new confirmation token. Only its {@see hash()} is stored. */
    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** Whether a value could be a token at all — checked before any lookup. */
    public static function isTokenShaped(mixed $token): bool
    {
        return is_string($token) && preg_match(self::TOKEN_PATTERN, $token) === 1;
    }

    /** What is stored and looked up: a database read never sees a usable token. */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** The wording's fingerprint: the same text always gives the same version. */
    public static function version(string $text): string
    {
        return hash('sha256', self::normalizeText($text));
    }

    public static function normalizeText(string $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }

    /**
     * An IP address as kept in consent evidence: keyed with the site's secret, so the stored
     * value can show two sign-ups came from one address without being the address — the whole
     * IPv4 space is small enough to reverse a plain hash in minutes.
     */
    public static function ipHash(string $ip, string $secret): ?string
    {
        return $ip !== '' ? hash_hmac('sha256', $ip, $secret . ':dispatch-signup-ip') : null;
    }

    /** Whether a checkbox-ish posted value means "ticked". */
    public static function ticked(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'on', 'yes', 'true'], true);
    }
}
