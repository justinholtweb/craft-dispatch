<?php

namespace justinholtweb\dispatchtests\unit\helpers;

use Codeception\Test\Unit;
use justinholtweb\dispatch\helpers\CssInliner;

class CssInlinerTest extends Unit
{
    public function testReturnsHtmlUnchangedWhenEmpty(): void
    {
        self::assertSame('', CssInliner::inline(''));
    }

    public function testReturnsHtmlUnchangedWithoutStyleBlocks(): void
    {
        $html = '<p>Hello world</p>';
        self::assertSame($html, CssInliner::inline($html));
    }

    public function testInlinesClassSelector(): void
    {
        $html = '<style>.box { color: red; }</style><div class="box">Hi</div>';
        $result = CssInliner::inline($html);

        self::assertStringContainsString('style="color: red"', $result);
    }

    public function testInlinesIdSelector(): void
    {
        $html = '<style>#hero { padding: 10px; }</style><div id="hero">Hi</div>';
        $result = CssInliner::inline($html);

        self::assertStringContainsString('style="padding: 10px"', $result);
    }

    public function testInlinesTagSelector(): void
    {
        $html = '<style>p { margin: 0; }</style><p>Hi</p>';
        $result = CssInliner::inline($html);

        self::assertStringContainsString('style="margin: 0"', $result);
    }

    public function testMergesWithExistingInlineStyle(): void
    {
        $html = '<style>.box { color: red; }</style><div class="box" style="font-weight: bold;">Hi</div>';
        $result = CssInliner::inline($html);

        self::assertStringContainsString('font-weight: bold', $result);
        self::assertStringContainsString('color: red', $result);
    }

    public function testStripsCommentsFromInlinedDeclarations(): void
    {
        $html = '<style>p { margin: 0; /* inner comment */ padding: 5px; }</style><p>Hi</p>';
        $result = CssInliner::inline($html);

        // Comments inside the declaration block must not leak into the inlined style.
        // (A retained <style> block may still contain the comment; only the
        // inlined attribute needs to be clean.)
        self::assertStringContainsString('style="margin: 0; padding: 5px"', $result);
    }

    public function testHandlesMultipleSelectorsInOneRule(): void
    {
        $html = '<style>h1, h2 { color: blue; }</style><h1>A</h1><h2>B</h2>';
        $result = CssInliner::inline($html);

        self::assertSame(2, substr_count($result, 'style="color: blue"'));
    }

    public function testHandlesSelfClosingTags(): void
    {
        $html = '<style>img { border: 0; }</style><img src="x.png" />';
        $result = CssInliner::inline($html);

        self::assertStringContainsString('style="border: 0"', $result);
        // The self-closing marker must be preserved.
        self::assertStringContainsString('/>', $result);
    }

    public function testIgnoresUnsupportedComplexSelectors(): void
    {
        $html = '<style>.a .b { color: red; }</style><div class="b">Hi</div>';
        $result = CssInliner::inline($html);

        // Descendant selectors are not supported, so nothing is inlined.
        self::assertStringNotContainsString('style="color: red"', $result);
    }
}
