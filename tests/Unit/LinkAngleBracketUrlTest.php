<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit;

use PhpMarkdown\Node\Block\DocumentNode;
use PhpMarkdown\Node\Block\ParagraphNode;
use PhpMarkdown\Parser\InlineParser;
use PhpMarkdown\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

final class LinkAngleBracketUrlTest extends TestCase
{
    private InlineParser $parser;
    private HtmlRenderer $renderer;

    protected function setUp(): void
    {
        $this->parser   = new InlineParser();
        $this->renderer = new HtmlRenderer();
    }

    private function renderInline(string $input): string
    {
        $nodes    = $this->parser->parse($input);
        $para     = new ParagraphNode($nodes);
        $document = new DocumentNode([$para]);
        return $this->renderer->render($document);
    }

    public function test_simple_angle_bracket_url(): void
    {
        $html = $this->renderInline('[foo](<https://example.com>)');
        $this->assertSame('<p><a href="https://example.com">foo</a></p>', $html);
    }

    public function test_angle_bracket_url_with_spaces(): void
    {
        $html = $this->renderInline('[foo](<my url with spaces>)');
        $this->assertSame('<p><a href="my url with spaces">foo</a></p>', $html);
    }

    public function test_angle_bracket_empty_url(): void
    {
        $html = $this->renderInline('[foo](<>)');
        $this->assertSame('<p><a href="">foo</a></p>', $html);
    }

    public function test_angle_bracket_url_with_title(): void
    {
        $html = $this->renderInline('[foo](<https://example.com> "title")');
        $this->assertSame('<p><a href="https://example.com" title="title">foo</a></p>', $html);
    }

    public function test_angle_bracket_url_in_image(): void
    {
        $html = $this->renderInline('![alt](<https://example.com/img.png>)');
        $this->assertSame('<p><img src="https://example.com/img.png" alt="alt"></p>', $html);
    }

    public function test_unclosed_angle_bracket_is_not_a_link(): void
    {
        $html = $this->renderInline('[foo](<not closed)');
        $this->assertStringNotContainsString('<a href=', $html);
    }

    public function test_angle_bracket_url_with_inner_lt_is_invalid(): void
    {
        $html = $this->renderInline('[foo](<url<bad>)');
        $this->assertStringNotContainsString('<a href=', $html);
    }

    public function test_angle_bracket_url_with_newline_is_invalid(): void
    {
        $html = $this->renderInline("[foo](<url\nbar>)");
        $this->assertStringNotContainsString('<a href=', $html);
    }

    public function test_backslash_escaped_gt_in_angle_bracket_url(): void
    {
        // \> in an angle-bracket URL is a backslash-escape for >; the rendered href
        // HTML-escapes the literal > to &gt; per standard attribute encoding.
        $html = $this->renderInline('[foo](<url\>bar>)');
        $this->assertSame('<p><a href="url&gt;bar">foo</a></p>', $html);
    }

    public function test_ampersand_in_angle_bracket_url_is_escaped_in_href(): void
    {
        $html = $this->renderInline('[foo](<https://example.com?a=1&b=2>)');
        $this->assertSame('<p><a href="https://example.com?a=1&amp;b=2">foo</a></p>', $html);
    }

    public function test_cr_in_angle_bracket_url_is_rejected(): void
    {
        // CR is stripped by browsers in href — would allow javascript: scheme bypass.
        $html = $this->renderInline("[foo](<java\rscript:alert(1)>)");
        $this->assertStringNotContainsString('<a href=', $html);
    }

    public function test_tab_in_angle_bracket_url_is_rejected(): void
    {
        // TAB is stripped by browsers in href — same bypass vector as CR.
        $html = $this->renderInline("[foo](<java\tscript:alert(1)>)");
        $this->assertStringNotContainsString('<a href=', $html);
    }
}
