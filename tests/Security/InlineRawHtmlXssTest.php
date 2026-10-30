<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Security;

use PhpMarkdown\MarkdownParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Inline raw HTML in allowRawHtml mode must go through the DOM sanitizer,
 * in every inline container (paragraph, heading, list item, footnote body).
 */
final class InlineRawHtmlXssTest extends TestCase
{
    private MarkdownParser $parser;

    protected function setUp(): void
    {
        $this->parser = new MarkdownParser();
    }

    /** @return array<string, array{string}> */
    public static function payloads(): array
    {
        return [
            'script at line start'         => ['<script>alert(1)</script>'],
            'script mid paragraph'         => ['hi <script>alert(1)</script>'],
            'svg slash-separated handler'  => ['hi <svg/onload=alert(1)>'],
            'img slash-separated handler'  => ['hi <img/src=x/onerror=alert(1)>'],
            'href with leading space'      => ['hi <a href=" javascript:alert(1)">x</a>'],
            'href entity-encoded scheme'   => ['hi <a href="&#106;avascript:alert(1)">x</a>'],
            'iframe'                       => ['hi <iframe src="https://evil.example"></iframe>'],
            'style tag'                    => ['<style>body{display:none}</style>'],
            'heading'                      => ['# Title <img src=x onerror=alert(1)>'],
            'list item'                    => ['- item <svg onload=alert(1)>'],
            'nested in emphasis'           => ['*a <img src=x onerror=alert(1)>*'],
            'link text'                    => ['[a <img src=x onerror=alert(1)>](/u)'],
            'footnote body'                => ["x[^1]\n\n[^1]: <img src=x onerror=alert(1)>"],
        ];
    }

    #[DataProvider('payloads')]
    public function testDangerousInlineHtmlIsNeutralised(string $markdown): void
    {
        $html = strtolower($this->parser->parse($markdown, allowRawHtml: true));

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<svg', $html);
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringNotContainsString('<style', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertDoesNotMatchRegularExpression('/<[^>]*\son\w+\s*=/', $html);
    }

    public function testSafeInlineHtmlIsKept(): void
    {
        $html = $this->parser->parse('Café **gras** <b class="x">ok</b> & <a href="https://example.com">l</a>', allowRawHtml: true);

        $this->assertSame(
            '<p>Café <strong>gras</strong> <b class="x">ok</b> &amp; <a href="https://example.com">l</a></p>' . "\n",
            $html,
        );
    }

    public function testParagraphWithoutRawHtmlIsNotReserialised(): void
    {
        // No RawHtmlInlineNode → renderer output is untouched (XHTML <br />, no sanitizer pass).
        $html = $this->parser->parse("a  \nb", allowRawHtml: true);

        $this->assertSame("<p>a<br />\nb</p>\n", $html);
    }
}
