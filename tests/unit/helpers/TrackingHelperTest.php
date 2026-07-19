<?php

namespace justinholtweb\dispatchtests\unit\helpers;

use Codeception\Test\Unit;
use justinholtweb\dispatch\helpers\TrackingHelper;

class TrackingHelperTest extends Unit
{
    public function testTokenRoundTrips(): void
    {
        $token = TrackingHelper::generateToken(1, 2);

        self::assertTrue(TrackingHelper::verifyToken($token, 1, 2));
    }

    public function testTokenIsDeterministic(): void
    {
        self::assertSame(
            TrackingHelper::generateToken(5, 9),
            TrackingHelper::generateToken(5, 9),
        );
    }

    public function testVerifyTokenRejectsTamperedToken(): void
    {
        $token = TrackingHelper::generateToken(1, 2);

        self::assertFalse(TrackingHelper::verifyToken($token . 'x', 1, 2));
    }

    public function testVerifyTokenRejectsDifferentParts(): void
    {
        $token = TrackingHelper::generateToken(1, 2);

        self::assertFalse(TrackingHelper::verifyToken($token, 1, 3));
    }

    public function testClickTokenRoundTrips(): void
    {
        $url = 'https://example.com/page';
        $token = TrackingHelper::generateClickToken(1, 2, $url);

        self::assertTrue(TrackingHelper::verifyClickToken($token, 1, 2, $url));
    }

    public function testClickTokenRejectsDifferentUrl(): void
    {
        $token = TrackingHelper::generateClickToken(1, 2, 'https://example.com/a');

        self::assertFalse(TrackingHelper::verifyClickToken($token, 1, 2, 'https://example.com/b'));
    }

    public function testInjectTrackingPixelInsertsBeforeBody(): void
    {
        $html = '<html><body><p>Hi</p></body></html>';
        $result = TrackingHelper::injectTrackingPixel($html, 1, 2);

        self::assertStringContainsString('<img', $result);
        self::assertStringContainsString('dispatch/track/open', $result);
        // Pixel must be placed inside the body, before the closing tag.
        self::assertStringEndsWith('</body></html>', $result);
        self::assertLessThan(
            strpos($result, '</body>'),
            strpos($result, '<img'),
            'Pixel should appear before the closing body tag',
        );
    }

    public function testInjectTrackingPixelAppendsWhenNoBody(): void
    {
        $html = '<p>Hi</p>';
        $result = TrackingHelper::injectTrackingPixel($html, 1, 2);

        self::assertStringStartsWith('<p>Hi</p>', $result);
        self::assertStringContainsString('<img', $result);
    }

    public function testRewriteLinksRewritesNormalLinks(): void
    {
        $html = '<a href="https://example.com/page">Link</a>';
        $result = TrackingHelper::rewriteLinks($html, 1, 2);

        self::assertStringContainsString('dispatch/track/click', $result);
    }

    public function testRewriteLinksSkipsAnchorsMailtoAndTel(): void
    {
        $html = '<a href="#top">Top</a><a href="mailto:a@b.com">Mail</a><a href="tel:123">Call</a>';
        $result = TrackingHelper::rewriteLinks($html, 1, 2);

        self::assertStringNotContainsString('dispatch/track/click', $result);
        self::assertStringContainsString('href="#top"', $result);
        self::assertStringContainsString('href="mailto:a@b.com"', $result);
        self::assertStringContainsString('href="tel:123"', $result);
    }

    public function testRewriteLinksSkipsUnsubscribeAndTrackingLinks(): void
    {
        $html = '<a href="https://site.test/dispatch/unsubscribe?x=1">Unsub</a>'
            . '<a href="https://site.test/dispatch/track/open?y=2">Pixel</a>';
        $result = TrackingHelper::rewriteLinks($html, 1, 2);

        self::assertStringNotContainsString('dispatch/track/click', $result);
    }
}
