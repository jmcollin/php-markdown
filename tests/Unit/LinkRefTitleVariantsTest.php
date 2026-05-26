<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit;

use PhpMarkdown\Lexer\Lexer;
use PhpMarkdown\Parser\Parser;
use PhpMarkdown\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Full-stack tests for link reference definition title delimiter variants
 * (CommonMark §4.7): double-quote, single-quote, parenthesised, and multiline.
 */
final class LinkRefTitleVariantsTest extends TestCase
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

    /** AC-1: double-quote title (regression guard). */
    public function test_ref_link_with_double_quote_title(): void
    {
        $html = $this->renderMarkdown("[foo]: /url \"title\"\n\n[foo]");
        $this->assertSame('<p><a href="/url" title="title">foo</a></p>', $html);
    }

    /** AC-2: single-quote title. */
    public function test_ref_link_with_single_quote_title(): void
    {
        $html = $this->renderMarkdown("[foo]: /url 'title'\n\n[foo]");
        $this->assertSame('<p><a href="/url" title="title">foo</a></p>', $html);
    }

    /** AC-3: parenthesised title. */
    public function test_ref_link_with_parenthesised_title(): void
    {
        $html = $this->renderMarkdown("[foo]: /url (title)\n\n[foo]");
        $this->assertSame('<p><a href="/url" title="title">foo</a></p>', $html);
    }

    /** AC-4: image ref with single-quote title. */
    public function test_ref_image_with_single_quote_title(): void
    {
        $html = $this->renderMarkdown("[img]: /img.png 'alt title'\n\n![img]");
        $this->assertSame('<p><img src="/img.png" alt="img" title="alt title"></p>', $html);
    }

    /** AC-5: title on a separate continuation line (CommonMark §4.7). */
    public function test_ref_link_with_title_on_separate_line(): void
    {
        $html = $this->renderMarkdown("[foo]: /url\n'title'\n\n[foo]");
        $this->assertSame('<p><a href="/url" title="title">foo</a></p>', $html);
    }

    /**
     * AC-6: mismatched delimiters — the entire line is not a valid link ref def.
     * The ref [foo] is undefined, so it renders as literal text, not a link.
     */
    public function test_mismatched_title_delimiters_not_a_valid_def(): void
    {
        $html = $this->renderMarkdown("[foo]: /url 'bad\"\n\n[foo]");
        $this->assertStringNotContainsString('<a href=', $html);
    }

    /** AC-7: no title — renders without title attribute. */
    public function test_ref_link_with_no_title(): void
    {
        $html = $this->renderMarkdown("[foo]: /url\n\n[foo]");
        $this->assertSame('<p><a href="/url">foo</a></p>', $html);
    }

    /** Edge: parenthesised title must not contain unescaped ( — treated as invalid. */
    public function test_parenthesised_title_with_unescaped_open_paren_is_invalid(): void
    {
        // "(bad(title)" contains an unescaped ( — does not match paren-title branch.
        // Line does not match PATTERN_LINK_DEFINITION → falls through to PARAGRAPH.
        $html = $this->renderMarkdown("[foo]: /url (bad(title)\n\n[foo]");
        $this->assertStringNotContainsString('<a href=', $html);
    }

    /** Edge: single-quote title may contain double quotes. */
    public function test_single_quote_title_may_contain_double_quotes(): void
    {
        $html = $this->renderMarkdown("[foo]: /url 'say \"hi\"'\n\n[foo]");
        $this->assertStringContainsString('title=', $html);
        $this->assertStringContainsString('say', $html);
    }

    /** Edge: double-quote title may contain single quotes. */
    public function test_double_quote_title_may_contain_single_quotes(): void
    {
        $html = $this->renderMarkdown("[foo]: /url \"it's fine\"\n\n[foo]");
        $this->assertStringContainsString('title=', $html);
        $this->assertStringContainsString("it", $html);
    }

    /** Edge: title on separate line with double-quote delimiter. */
    public function test_ref_link_with_double_quote_title_on_separate_line(): void
    {
        $html = $this->renderMarkdown("[foo]: /url\n\"title\"\n\n[foo]");
        $this->assertSame('<p><a href="/url" title="title">foo</a></p>', $html);
    }

    /** Edge: title on separate line with parenthesised delimiter. */
    public function test_ref_link_with_paren_title_on_separate_line(): void
    {
        $html = $this->renderMarkdown("[foo]: /url\n(title)\n\n[foo]");
        $this->assertSame('<p><a href="/url" title="title">foo</a></p>', $html);
    }

    /** Edge: orphan title line not preceded by a URL-only ref def must NOT be consumed as a title. */
    public function test_orphan_title_line_not_consumed_as_title(): void
    {
        $html = $this->renderMarkdown("'orphan'\n\n[foo]: /url\n\n[foo]");
        $this->assertSame('<p>&#039;orphan&#039;</p><p><a href="/url">foo</a></p>', $html);
    }
}
