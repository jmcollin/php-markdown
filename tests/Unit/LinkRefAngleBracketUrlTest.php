<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit;

use PhpMarkdown\Lexer\Lexer;
use PhpMarkdown\Parser\Parser;
use PhpMarkdown\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Full-stack tests for link reference definitions with angle-bracket URLs
 * (CommonMark §4.7): angle brackets are stripped, escapes are resolved, and
 * invalid forms are rejected.
 */
final class LinkRefAngleBracketUrlTest extends TestCase
{
    private Lexer $lexer;
    private Parser $parser;
    private HtmlRenderer $renderer;

    protected function setUp(): void
    {
        $this->lexer    = new Lexer();
        $this->parser   = new Parser();
        $this->renderer = new HtmlRenderer();
    }

    private function renderMarkdown(string $markdown): string
    {
        return $this->renderer->render(
            $this->parser->parse($this->lexer->tokenize($markdown))
        );
    }

    /** AC-1: angle-bracket URL — brackets stripped, href is the bare URL. */
    public function test_angle_bracket_url_brackets_are_stripped(): void
    {
        $html = $this->renderMarkdown("[foo]: <https://example.com>\n\n[foo]");
        $this->assertSame('<p><a href="https://example.com">foo</a></p>', $html);
    }

    /** AC-2: angle-bracket URL with double-quote title. */
    public function test_angle_bracket_url_with_double_quote_title(): void
    {
        $html = $this->renderMarkdown("[foo]: <https://example.com> \"title\"\n\n[foo]");
        $this->assertSame('<p><a href="https://example.com" title="title">foo</a></p>', $html);
    }

    /** AC-3: angle-bracket URL with single-quote title. */
    public function test_angle_bracket_url_with_single_quote_title(): void
    {
        $html = $this->renderMarkdown("[foo]: <https://example.com> 'title'\n\n[foo]");
        $this->assertSame('<p><a href="https://example.com" title="title">foo</a></p>', $html);
    }

    /** AC-4: empty angle brackets — href is an empty string. */
    public function test_empty_angle_brackets_produce_empty_href(): void
    {
        $html = $this->renderMarkdown("[foo]: <>\n\n[foo]");
        $this->assertSame('<p><a href="">foo</a></p>', $html);
    }

    /** AC-5: angle-bracket URL used with an image reference. */
    public function test_angle_bracket_url_with_image_ref(): void
    {
        $html = $this->renderMarkdown("[img]: <https://example.com/img.png>\n\n![img]");
        $this->assertSame('<p><img src="https://example.com/img.png" alt="img"></p>', $html);
    }

    /** AC-6: angle brackets stripped — only URL content stored (no brackets in href attr). */
    public function test_angle_brackets_not_present_in_stored_href(): void
    {
        $html = $this->renderMarkdown("[foo]: <https://example.com>\n\n[foo]");
        $this->assertStringNotContainsString('href="<', $html);
        $this->assertStringNotContainsString('>"', $html);
    }

    /** AC-7: unclosed angle bracket — not a valid ref def; link not resolved. */
    public function test_unclosed_angle_bracket_is_not_a_valid_ref_def(): void
    {
        $html = $this->renderMarkdown("[foo]: <https://example.com\n\n[foo]");
        $this->assertStringNotContainsString('<a href=', $html);
    }

    /** AC-8: angle-bracket URL may not span multiple lines (concrete form). */
    public function test_angle_bracket_url_spanning_multiple_lines_is_invalid(): void
    {
        $html = $this->renderMarkdown("[foo]: <https://example.com\n>\n\n[foo]");
        $this->assertStringNotContainsString('<a href=', $html);
    }

    /** Edge: backslash-escaped > inside angle-bracket URL resolves to > in href (HTML-escaped as &gt;). */
    public function test_backslash_escaped_gt_inside_angle_bracket_url(): void
    {
        // \> is unescaped to > during lexing; the renderer HTML-escapes > to &gt; in the attribute.
        $html = $this->renderMarkdown("[foo]: <url\\>path>\n\n[foo]");
        $this->assertSame('<p><a href="url&gt;path">foo</a></p>', $html);
    }

    /** Edge: unescaped < inside angle-bracket URL makes the definition invalid. */
    public function test_unescaped_lt_inside_angle_bracket_url_is_invalid(): void
    {
        $html = $this->renderMarkdown("[foo]: <ur<l>\n\n[foo]");
        $this->assertStringNotContainsString('<a href=', $html);
    }

    /** Edge: ampersand in angle-bracket URL is HTML-escaped in the rendered href. */
    public function test_ampersand_in_angle_bracket_url_is_html_escaped(): void
    {
        $html = $this->renderMarkdown("[foo]: <https://example.com?a=1&b=2>\n\n[foo]");
        $this->assertSame('<p><a href="https://example.com?a=1&amp;b=2">foo</a></p>', $html);
    }

    /** Edge: parenthesised title with angle-bracket URL (regression guard). */
    public function test_angle_bracket_url_with_paren_title(): void
    {
        $html = $this->renderMarkdown("[foo]: <https://example.com> (title)\n\n[foo]");
        $this->assertSame('<p><a href="https://example.com" title="title">foo</a></p>', $html);
    }

    /** Regression: bare URL ref defs still work after the regex change. */
    public function test_bare_url_ref_def_still_works(): void
    {
        $html = $this->renderMarkdown("[foo]: https://example.com\n\n[foo]");
        $this->assertSame('<p><a href="https://example.com">foo</a></p>', $html);
    }

    /** Regression: bare URL ref def with title still works after the regex change. */
    public function test_bare_url_ref_def_with_title_still_works(): void
    {
        $html = $this->renderMarkdown("[foo]: /url \"a title\"\n\n[foo]");
        $this->assertSame('<p><a href="/url" title="a title">foo</a></p>', $html);
    }
}
