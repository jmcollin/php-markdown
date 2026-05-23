<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Integration;

use PhpMarkdown\MarkdownParser;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

// MarkdownParser::guard() always calls \Normalizer::normalize — all tests require ext-intl.
#[RequiresPhpExtension('intl')]
final class HtmlPassthroughTest extends TestCase
{
    private MarkdownParser $parser;

    protected function setUp(): void
    {
        $this->parser = new MarkdownParser();
    }

    // -------------------------------------------------------------------------
    // Test 1 — Mixed safe and unsafe HTML in a single document
    // -------------------------------------------------------------------------

    public function testMixedSafeAndUnsafeHtmlInDocument(): void
    {
        $md = '<div>safe<script>evil()</script></div>' . "\n\n"
            . '<span onclick="evil()">click</span>' . "\n\n"
            . 'Visit <a href="javascript:alert(1)">here</a> now';

        $html = $this->parser->parse($md, allowRawHtml: true);

        // Block sanitizer: safe div preserved; script child inside div removed
        $this->assertStringContainsString('<div>', $html);
        $this->assertStringContainsString('safe', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('evil()', $html);

        // Block sanitizer: onclick stripped from span, span tag content preserved
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringContainsString('<span>', $html);
        $this->assertStringContainsString('click', $html);

        // Inline sanitizer: javascript href stripped, anchor tag kept
        $this->assertStringNotContainsString('javascript', $html);
        $this->assertStringContainsString('<a>', $html);
        $this->assertStringContainsString('here', $html);
    }

    // -------------------------------------------------------------------------
    // Test 2 — Inline javascript href stripped in allowRawHtml mode
    // -------------------------------------------------------------------------

    public function testInlineJavascriptHrefStrippedInAllowRawHtmlMode(): void
    {
        $md = 'Click <a href="javascript:xss()">here</a> and <em>safe</em>';

        $html = $this->parser->parse($md, allowRawHtml: true);

        $this->assertStringNotContainsString('javascript', $html);
        $this->assertStringNotContainsString('href=', $html);
        $this->assertStringContainsString('<a>', $html);
        $this->assertStringContainsString('here', $html);
        $this->assertStringContainsString('<em>safe</em>', $html);

        // Negative control: in default mode (allowRawHtml: false), raw HTML is escaped.
        $htmlEscaped = $this->parser->parse($md, allowRawHtml: false);
        $this->assertStringNotContainsString('<a>', $htmlEscaped);
        $this->assertStringContainsString('&lt;a', $htmlEscaped);
    }

    // -------------------------------------------------------------------------
    // Test 3 — Reference links with javascript URL rejected in allowRawHtml mode
    // -------------------------------------------------------------------------

    public function testReferenceLinksWithJavascriptUrlRejectedInAllowRawHtmlMode(): void
    {
        $md = '[foo][bar]' . "\n\n" . '[bar]: javascript:alert(1)';

        $html = $this->parser->parse($md, allowRawHtml: true);

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('href=', $html);

        // The parser rejects the reference entirely — no anchor tag created.
        $this->assertStringNotContainsString('<a', $html);
    }
}
