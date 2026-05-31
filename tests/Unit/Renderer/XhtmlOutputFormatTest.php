<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit\Renderer;

use PhpMarkdown\MarkdownParser;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Acceptance tests for XHTML-compatible output format.
 *
 * All block elements must end with a newline.
 * Void elements (<hr>, <br>, <img>) must use self-closing syntax.
 * Inline nodes must produce no trailing newline of their own.
 */
#[RequiresPhpExtension('intl')]
final class XhtmlOutputFormatTest extends TestCase
{
    private MarkdownParser $parser;

    protected function setUp(): void
    {
        $this->parser = new MarkdownParser();
    }

    private function parseAndRender(string $markdown): string
    {
        return $this->parser->parse($markdown);
    }

    // ── Headings ──────────────────────────────────────────────────────────────

    public function testAtxHeadingLevel1EndsWithNewline(): void
    {
        $out = $this->parseAndRender('# Foo');
        $this->assertSame("<h1>Foo</h1>\n", $out);
    }

    public function testAtxHeadingLevel2EndsWithNewline(): void
    {
        $out = $this->parseAndRender('## Bar');
        $this->assertSame("<h2>Bar</h2>\n", $out);
    }

    // ── Paragraph ─────────────────────────────────────────────────────────────

    public function testParagraphEndsWithNewline(): void
    {
        $out = $this->parseAndRender('hello');
        $this->assertSame("<p>hello</p>\n", $out);
    }

    // ── Code blocks ───────────────────────────────────────────────────────────

    public function testIndentedCodeBlockEndsWithNewline(): void
    {
        $out = $this->parseAndRender("    a simple\n      indented code block");
        $this->assertSame("<pre><code>a simple\n  indented code block\n</code></pre>\n", $out);
    }

    public function testFencedCodeBlockEndsWithNewline(): void
    {
        $out = $this->parseAndRender("```\n<\n >\n```");
        // The lexer preserves content as-is; the renderer wraps in <pre><code>...</code></pre>\n.
        $this->assertSame("<pre><code>&lt;\n &gt;</code></pre>\n", $out);
    }

    public function testFencedCodeBlockWithLanguageEndsWithNewline(): void
    {
        $out = $this->parseAndRender("```php\necho 1;\n```");
        $this->assertSame("<pre><code class=\"language-php\">echo 1;</code></pre>\n", $out);
    }

    // ── Void elements: self-closing syntax ────────────────────────────────────

    public function testHorizontalRuleRendersAsSelfClosingWithNewline(): void
    {
        $out = $this->parseAndRender('***');
        $this->assertSame("<hr />\n", $out);
    }

    public function testHardLineBreakRendersAsSelfClosingWithTrailingNewline(): void
    {
        $out = $this->parseAndRender("foo  \nbar");
        $this->assertSame("<p>foo<br />\nbar</p>\n", $out);
    }

    public function testImageRendersAsSelfClosingWithTitleAttribute(): void
    {
        $out = $this->parseAndRender('![alt](/url "title")');
        $this->assertSame("<p><img src=\"/url\" alt=\"alt\" title=\"title\" /></p>\n", $out);
    }

    public function testImageWithoutTitleRendersAsSelfClosing(): void
    {
        $out = $this->parseAndRender('![alt](/url)');
        $this->assertSame("<p><img src=\"/url\" alt=\"alt\" /></p>\n", $out);
    }

    // ── Blockquotes ───────────────────────────────────────────────────────────

    public function testBlockquoteHasInternalNewlines(): void
    {
        $out = $this->parseAndRender('> bar');
        $this->assertSame("<blockquote>\n<p>bar</p>\n</blockquote>\n", $out);
    }

    public function testNestedBlockquoteHasNewlinesAtEveryLevel(): void
    {
        $out = $this->parseAndRender('> > nested');
        $this->assertSame("<blockquote>\n<blockquote>\n<p>nested</p>\n</blockquote>\n</blockquote>\n", $out);
    }

    // ── Lists ─────────────────────────────────────────────────────────────────

    public function testTightUnorderedListHasNewlines(): void
    {
        $out = $this->parseAndRender("- foo\n- bar");
        $this->assertSame("<ul>\n<li>foo</li>\n<li>bar</li>\n</ul>\n", $out);
    }

    public function testTightOrderedListHasNewlines(): void
    {
        $out = $this->parseAndRender("1. foo\n2. bar");
        $this->assertSame("<ol>\n<li>foo</li>\n<li>bar</li>\n</ol>\n", $out);
    }

    public function testLooseUnorderedListWrapsItemsInParagraph(): void
    {
        $out = $this->parseAndRender("- foo\n\n- bar");
        $this->assertSame("<ul>\n<li>\n<p>foo</p>\n</li>\n<li>\n<p>bar</p>\n</li>\n</ul>\n", $out);
    }

    // ── Inline nodes: no trailing newline of their own ────────────────────────

    public function testInlineNodesHaveNoTrailingNewline(): void
    {
        // Strong, link, and code are all inline — the outer <p> carries the \n,
        // no inline node should inject a newline between itself and its siblings.
        $out = $this->parseAndRender('**bold** [link](https://example.com) `code`');

        // Outer paragraph ends with exactly one newline.
        $this->assertStringEndsWith("</p>\n", $out);

        // The string between <p> and </p> must contain no raw newlines
        // (only the paragraph wrapper provides the trailing \n).
        preg_match('/<p>(.*?)<\/p>/s', $out, $matches);
        $this->assertArrayHasKey(1, $matches);
        $this->assertStringNotContainsString("\n", $matches[1]);
    }
}
