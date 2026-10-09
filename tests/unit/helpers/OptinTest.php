<?php

namespace justinholtweb\dispatchtests\unit\helpers;

use Codeception\Test\Unit;
use justinholtweb\dispatch\helpers\Optin;

class OptinTest extends Unit
{
    public function testTokensAre43UrlSafeCharactersAndUnique(): void
    {
        $a = Optin::newToken();
        $b = Optin::newToken();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $a);
        self::assertNotSame($a, $b);
        self::assertTrue(Optin::isTokenShaped($a));
    }

    public function testOnlyTokenShapedValuesPass(): void
    {
        foreach (['', '*', '%', str_repeat('A', 42), str_repeat('A', 44), str_repeat('A', 42) . '*', null, 123, ['x']] as $bad) {
            self::assertFalse(Optin::isTokenShaped($bad), var_export($bad, true));
        }
    }

    public function testHashIsSha256(): void
    {
        self::assertSame(hash('sha256', 'abc'), Optin::hash('abc'));
    }

    public function testVersionIgnoresLineEndingsAndOuterWhitespace(): void
    {
        self::assertSame(Optin::version("I agree\nto it"), Optin::version("  I agree\r\nto it \n"));
        self::assertNotSame(Optin::version('I agree'), Optin::version('I agree!'));
    }

    public function testIpHashIsKeyedAndBlankForNoAddress(): void
    {
        self::assertNull(Optin::ipHash('', 'k'));
        self::assertNotSame(hash('sha256', '1.2.3.4'), Optin::ipHash('1.2.3.4', 'k'));
        self::assertNotSame(Optin::ipHash('1.2.3.4', 'k'), Optin::ipHash('1.2.3.4', 'other'));
        self::assertSame(Optin::ipHash('1.2.3.4', 'k'), Optin::ipHash('1.2.3.4', 'k'));
    }

    public function testHtmlEscapesAndLinksOnlySafeUrls(): void
    {
        self::assertSame('I agree to the <a href="/privacy" target="_blank" rel="noopener">policy</a>', Optin::html('I agree to the [policy](/privacy)'));
        self::assertSame('&lt;b&gt;x&lt;/b&gt;', Optin::html('<b>x</b>'));
        self::assertSame('[x](javascript:alert(1))', Optin::html('[x](javascript:alert(1))'));
        self::assertSame('[x](//evil.test)', Optin::html('[x](//evil.test)'));
        self::assertStringContainsString('href="https://example.com/a?b=1&amp;c=2"', Optin::html('[x](https://example.com/a?b=1&c=2)'));
    }

    public function testTicked(): void
    {
        foreach ([true, 1, '1', 'on', 'yes', 'true'] as $yes) {
            self::assertTrue(Optin::ticked($yes));
        }

        foreach ([false, 0, '0', '', null, 'no', []] as $no) {
            self::assertFalse(Optin::ticked($no));
        }
    }
}
