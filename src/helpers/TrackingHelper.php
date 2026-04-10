<?php

namespace justinholtweb\dispatch\helpers;

use Craft;
use craft\helpers\UrlHelper;

class TrackingHelper
{
    private const HMAC_ALGO = 'sha256';

    public static function generateToken(int ...$parts): string
    {
        $secret = self::_getSecret();
        $data = implode(':', $parts);

        return hash_hmac(self::HMAC_ALGO, $data, $secret);
    }

    public static function verifyToken(string $token, int ...$parts): bool
    {
        $expected = self::generateToken(...$parts);
        return hash_equals($expected, $token);
    }

    public static function injectTrackingPixel(string $html, int $campaignId, int $subscriberId): string
    {
        $token = self::generateToken($campaignId, $subscriberId);

        $pixelUrl = UrlHelper::siteUrl('dispatch/track/open', [
            'cid' => $campaignId,
            'sid' => $subscriberId,
            'token' => $token,
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
            function ($match) use ($campaignId, $subscriberId) {
                $url = $match[2];

                // Skip anchors, mailto, tel, and unsubscribe links
                if (str_starts_with($url, '#') ||
                    str_starts_with($url, 'mailto:') ||
                    str_starts_with($url, 'tel:') ||
                    str_contains($url, 'dispatch/unsubscribe') ||
                    str_contains($url, 'dispatch/track/')) {
                    return $match[0];
                }

                $token = self::generateToken($campaignId, $subscriberId);

                $trackUrl = UrlHelper::siteUrl('dispatch/track/click', [
                    'cid' => $campaignId,
                    'sid' => $subscriberId,
                    'url' => $url,
                    'token' => $token,
                ]);

                return '<a ' . $match[1] . 'href="' . htmlspecialchars($trackUrl) . '"' . $match[3] . '>';
            },
            $html
        );
    }

    private static function _getSecret(): string
    {
        $secret = Craft::$app->getConfig()->getGeneral()->securityKey;
        return $secret . ':dispatch';
    }
}
