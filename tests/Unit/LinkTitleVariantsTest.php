<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit;

use PhpMarkdown\Node\Block\DocumentNode;
use PhpMarkdown\Node\Block\ParagraphNode;
use PhpMarkdown\Parser\InlineParser;
use PhpMarkdown\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

final class LinkTitleVariantsTest extends TestCase
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

    /** AC-1: existing double-quote title must continue to work (regression guard). */
    public function test_link_with_double_quote_title(): void
    {
        $html = $this->renderInline('[foo](url "title")');
        $this->assertSame('<p><a href="url" title="title">foo</a></p>', $html);
    }

    /** AC-2: single-quote title on a plain link. */
    public function test_link_with_single_quote_title(): void
    {
        $html = $this->renderInline("[foo](url 'title')");
        $this->assertSame('<p><a href="url" title="title">foo</a></p>', $html);
    }

    /** AC-3: parenthesised title on a plain link. */
    public function test_link_with_parenthesised_title(): void
    {
        $html = $this->renderInline('[foo](url (title))');
        $this->assertSame('<p><a href="url" title="title">foo</a></p>', $html);
    }

    /** AC-4: single-quote title on an image. */
    public function test_image_with_single_quote_title(): void
    {
        $html = $this->renderInline("![alt](img.png 'title')");
        $this->assertSame('<p><img src="img.png" alt="alt" title="title"></p>', $html);
    }

    /** AC-5: parenthesised title on an image. */
    public function test_image_with_parenthesised_title(): void
    {
        $html = $this->renderInline('![alt](img.png (title))');
        $this->assertSame('<p><img src="img.png" alt="alt" title="title"></p>', $html);
    }

    /** AC-6: single-quote title with angle-bracket URL. */
    public function test_angle_bracket_url_link_with_single_quote_title(): void
    {
        $html = $this->renderInline("[foo](<url> 'title')");
        $this->assertSame('<p><a href="url" title="title">foo</a></p>', $html);
    }

    /** AC-7: parenthesised title with angle-bracket URL. */
    public function test_angle_bracket_url_link_with_parenthesised_title(): void
    {
        $html = $this->renderInline('[foo](<url> (title))');
        $this->assertSame('<p><a href="url" title="title">foo</a></p>', $html);
    }

    /**
     * AC-8: mismatched delimiters ('title") are not a valid title.
     * The link should either render without a title attribute or fall back to literal text.
     */
    public function test_mismatched_title_delimiters_not_a_title(): void
    {
        $html = $this->renderInline('[foo](url \'title")');
        $this->assertStringNotContainsString('title=', $html);
    }

    /** AC-9: backslash-escaped apostrophe inside a single-quote title. */
    public function test_single_quote_title_with_escaped_inner_quote(): void
    {
        // The renderer applies htmlspecialchars(ENT_QUOTES), so ' is encoded as &#039; in attributes.
        $html = $this->renderInline("[foo](url 'it\\'s')");
        $this->assertSame('<p><a href="url" title="it&#039;s">foo</a></p>', $html);
    }

    /**
     * AC-10: unescaped opening parenthesis inside a parenthesised title makes the title invalid.
     * The paren-title body (?:[^()\\]|\\.)*  blocks both unescaped ( and ), so any unescaped (
     * inside prevents the paren-title branch from matching — the link is not parsed with a title.
     */
    public function test_parenthesised_title_with_unescaped_inner_paren_is_invalid(): void
    {
        // Input: [foo](url (bad(title))
        // The paren-title body rejects unescaped ( — no title match, no valid link.
        $html = $this->renderInline('[foo](url (bad(title))');
        $this->assertStringNotContainsString('title=', $html);
    }

    /** EDGE-1: whitespace between URL and title delimiter is mandatory. */
    public function test_no_whitespace_before_title_delimiter_not_a_title(): void
    {
        // url'title' — no space: single-quote absorbed into URL or no match at all.
        $html = $this->renderInline("[foo](url'title')");
        $this->assertStringNotContainsString('title=', $html);
    }

    /** EDGE-2: trailing spaces before the closing ) are tolerated. */
    public function test_trailing_whitespace_before_close_paren_is_tolerated(): void
    {
        $html = $this->renderInline("[foo](url 'title'   )");
        $this->assertSame('<p><a href="url" title="title">foo</a></p>', $html);
    }

    /**
     * EDGE-3: non-whitespace between the title close-delimiter and ) invalidates the title.
     * "[foo](url 'title' extra)" — "extra" is not whitespace, so the pattern must not match
     * as a link with title.
     */
    public function test_non_whitespace_after_title_close_delimiter_invalidates_title(): void
    {
        $html = $this->renderInline("[foo](url 'title' extra)");
        $this->assertStringNotContainsString('title="title"', $html);
    }

    /** EDGE-4: double-quote title on image with angle-bracket URL (regression). */
    public function test_image_angle_bracket_url_with_double_quote_title_regression(): void
    {
        $html = $this->renderInline('![alt](<img.png> "title")');
        $this->assertSame('<p><img src="img.png" alt="alt" title="title"></p>', $html);
    }
}
