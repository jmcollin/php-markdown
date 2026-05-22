<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit;

use PhpMarkdown\Lexer\Lexer;
use PhpMarkdown\Lexer\TokenType;
use PhpMarkdown\MarkdownParser;
use PhpMarkdown\Node\Block\ParagraphNode;
use PhpMarkdown\Node\Block\RawHtmlBlockNode;
use PhpMarkdown\Node\Block\HeadingNode;
use PhpMarkdown\Parser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Tests for block-level HTML detection (CommonMark §4.6 — story 28).
 *
 * WHY these tests exist: the lexer must promote block-level HTML tags to
 * HTML_BLOCK tokens so that the parser can emit them verbatim in the AST.
 * Inline tags (e.g. <span>) must NOT be promoted — they belong to inline
 * parsing (story 29). This distinction protects consumers from accidentally
 * having raw HTML content escaped or re-parsed as Markdown.
 */
#[RequiresPhpExtension('intl')]
final class HtmlBlockLexerTest extends TestCase
{
    private Lexer $lexer;

    protected function setUp(): void
    {
        $this->lexer = new Lexer();
    }

    // ── Scenario: Block div detected (single line, self-contained) ───────────

    public function testSingleLineDivProducesHtmlBlockToken(): void
    {
        // A self-contained block tag on its own line must be HTML_BLOCK, not PARAGRAPH.
        // Rationale: the consumer wants the raw HTML passed through, not entity-escaped.
        $tokens = $this->lexer->tokenize('<div class="foo">content</div>');

        $this->assertCount(1, $tokens);
        $this->assertSame(TokenType::HTML_BLOCK, $tokens[0]->type);
        $this->assertSame('<div class="foo">content</div>', $tokens[0]->content);
    }

    // ── Scenario: Multi-line HTML block ─────────────────────────────────────

    public function testMultiLineDivCollapsedToOneToken(): void
    {
        // A block tag that spans multiple lines must be merged into one HTML_BLOCK token.
        // Rationale: a split would make the AST impossible to render as-is.
        $md = "<div>\nhello\n</div>";
        $tokens = $this->lexer->tokenize($md);

        $htmlTokens = array_values(array_filter($tokens, fn($t) => $t->type === TokenType::HTML_BLOCK));
        $this->assertCount(1, $htmlTokens);
        $this->assertSame("<div>\nhello\n</div>", $htmlTokens[0]->content);
    }

    // ── Scenario: HTML block in mixed document ────────────────────────────────

    public function testMixedDocumentProducesCorrectNodeOrder(): void
    {
        // In a document mixing Markdown and raw HTML blocks, each element must
        // appear as the correct AST node in document order.
        $md = "# H1\n\n<div>raw</div>\n\ntext";

        $parser = new Parser();
        $lexer  = new Lexer();
        $tokens = $lexer->tokenize($md);
        $doc    = $parser->parse($tokens);

        $children = $doc->children;
        $this->assertCount(3, $children);
        $this->assertInstanceOf(HeadingNode::class, $children[0]);
        $this->assertInstanceOf(RawHtmlBlockNode::class, $children[1]);
        $this->assertInstanceOf(ParagraphNode::class, $children[2]);
    }

    // ── Scenario: Non-block tag is NOT captured as HTML block ─────────────────

    public function testSpanTagOnOwnLineIsParagraph(): void
    {
        // <span> is an inline element; a standalone line must NOT trigger HTML_BLOCK.
        // Rationale: inline HTML is handled in story 29; promoting it here would
        // break the inline-vs-block distinction.
        $tokens = $this->lexer->tokenize('<span>inline</span>');

        $this->assertCount(1, $tokens);
        $this->assertSame(TokenType::PARAGRAPH, $tokens[0]->type);
    }

    // ── Scenario: RawHtmlBlockNode in AST ─────────────────────────────────────

    public function testAsideProducesRawHtmlBlockNodeInAst(): void
    {
        // The parser must build a RawHtmlBlockNode for any HTML_BLOCK token.
        $parser = new Parser();
        $lexer  = new Lexer();
        $tokens = $lexer->tokenize('<aside>x</aside>');
        $doc    = $parser->parse($tokens);

        $this->assertCount(1, $doc->children);
        $node = $doc->children[0];
        $this->assertInstanceOf(RawHtmlBlockNode::class, $node);
        $this->assertSame('<aside>x</aside>', $node->content);
    }

    // ── Edge cases ────────────────────────────────────────────────────────────

    public function testIndentedUpToThreeSpacesStillDetected(): void
    {
        // CommonMark §4.6: up to 3 leading spaces of indentation must not prevent
        // block HTML detection.
        $tokens = $this->lexer->tokenize('   <div>indented</div>');

        $this->assertSame(TokenType::HTML_BLOCK, $tokens[0]->type);
    }

    public function testHtmlCommentOnOwnLineIsHtmlBlock(): void
    {
        // HTML comments on their own line are block HTML (CommonMark §4.6 type 2).
        $tokens = $this->lexer->tokenize('<!-- comment -->');

        $this->assertSame(TokenType::HTML_BLOCK, $tokens[0]->type);
        $this->assertSame('<!-- comment -->', $tokens[0]->content);
    }

    public function testDoctypeIsHtmlBlock(): void
    {
        // <!DOCTYPE html> is a block HTML declaration (CommonMark §4.6 type 4).
        $tokens = $this->lexer->tokenize('<!DOCTYPE html>');

        $this->assertSame(TokenType::HTML_BLOCK, $tokens[0]->type);
    }

    public function testBlockTagInsideFencedCodeIsNotDetected(): void
    {
        // Content inside a fenced code block must never be promoted to HTML_BLOCK.
        // Rationale: the fenced code context takes priority; the tag is literal text.
        $md = "```\n<div>inside code</div>\n```";
        $tokens = $this->lexer->tokenize($md);

        $htmlBlocks = array_filter($tokens, fn($t) => $t->type === TokenType::HTML_BLOCK);
        $this->assertCount(0, $htmlBlocks);

        $codeBlocks = array_filter($tokens, fn($t) => $t->type === TokenType::FENCED_CODE);
        $this->assertCount(1, $codeBlocks);
    }

    public function testUnclosedBlockTagSpansToNextBlankLine(): void
    {
        // An opening block tag with no closing tag on the same line starts a block
        // that spans until the next blank line (CommonMark §4.6 rule).
        $md = "<div>\ncontent line\n\nafter blank";
        $tokens = $this->lexer->tokenize($md);

        // First token: HTML_BLOCK containing the two lines before the blank
        $this->assertSame(TokenType::HTML_BLOCK, $tokens[0]->type);
        $this->assertSame("<div>\ncontent line", $tokens[0]->content);

        // Next: BLANK then PARAGRAPH
        $this->assertSame(TokenType::BLANK, $tokens[1]->type);
        $this->assertSame(TokenType::PARAGRAPH, $tokens[2]->type);
    }

    public function testOpeningTagAloneOnLineStartsHtmlBlock(): void
    {
        // An opening block-level tag without content on the same line must trigger
        // HTML_BLOCK even though it has no immediate visible content.
        $tokens = $this->lexer->tokenize('<article>');

        $this->assertSame(TokenType::HTML_BLOCK, $tokens[0]->type);
    }

    public function testClosingTagAloneOnLineStartsHtmlBlock(): void
    {
        // A closing block-level tag alone on a line also triggers HTML_BLOCK.
        $tokens = $this->lexer->tokenize('</section>');

        $this->assertSame(TokenType::HTML_BLOCK, $tokens[0]->type);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function blockTagProvider(): array
    {
        return [
            'address'    => ['<address>'],
            'article'    => ['<article>'],
            'aside'      => ['<aside>'],
            'blockquote' => ['<blockquote>'],
            'canvas'     => ['<canvas>'],
            'dd'         => ['<dd>'],
            'details'    => ['<details>'],
            'dialog'     => ['<dialog>'],
            'div'        => ['<div>'],
            'dl'         => ['<dl>'],
            'dt'         => ['<dt>'],
            'fieldset'   => ['<fieldset>'],
            'figcaption' => ['<figcaption>'],
            'figure'     => ['<figure>'],
            'footer'     => ['<footer>'],
            'form'       => ['<form>'],
            'h1'         => ['<h1>'],
            'h2'         => ['<h2>'],
            'h3'         => ['<h3>'],
            'h4'         => ['<h4>'],
            'h5'         => ['<h5>'],
            'h6'         => ['<h6>'],
            'header'     => ['<header>'],
            'hgroup'     => ['<hgroup>'],
            'hr'         => ['<hr>'],
            'li'         => ['<li>'],
            'main'       => ['<main>'],
            'menu'       => ['<menu>'],
            'nav'        => ['<nav>'],
            'noscript'   => ['<noscript>'],
            'ol'         => ['<ol>'],
            'p'          => ['<p>'],
            'pre'        => ['<pre>'],
            'section'    => ['<section>'],
            'summary'    => ['<summary>'],
            'table'      => ['<table>'],
            'ul'         => ['<ul>'],
        ];
    }

    #[DataProvider('blockTagProvider')]
    public function testAllBlockTagsRecognized(string $input): void
    {
        // Every tag in the CommonMark block-level list must be promoted to HTML_BLOCK.
        // Any omission would silently allow the tag content to be escaped as plain text.
        $tokens = $this->lexer->tokenize($input);

        $this->assertSame(TokenType::HTML_BLOCK, $tokens[0]->type, "Expected HTML_BLOCK for: {$input}");
    }

    // ── CommonMark §4.6 type 2: comment block ends at --> line ───────────────

    public function testCommentBlockEndsAtClosingMarker(): void
    {
        // CommonMark §4.6 type 2: the HTML_BLOCK for a comment must end on the line
        // containing -->. Content on subsequent lines before the next blank must NOT
        // be swallowed into the HTML block — it belongs to its own paragraph.
        $md = "<!-- comment -->\nsome paragraph\n\nafter";
        $tokens = $this->lexer->tokenize($md);

        // First token: HTML_BLOCK containing only the comment line.
        $this->assertSame(TokenType::HTML_BLOCK, $tokens[0]->type);
        $this->assertSame('<!-- comment -->', $tokens[0]->content);

        // "some paragraph" must be a PARAGRAPH, not part of the HTML block.
        $paragraphTokens = array_values(
            array_filter($tokens, fn($t) => $t->type === TokenType::PARAGRAPH)
        );
        $this->assertNotEmpty($paragraphTokens, 'Expected at least one PARAGRAPH token');
        $this->assertSame('some paragraph', $paragraphTokens[0]->content);
    }

    // ── Renderer integration ──────────────────────────────────────────────────

    public function testRawHtmlBlockRenderedVerbatim(): void
    {
        // allowRawHtml: true — renderer passes block HTML through the sanitizer.
        // Attributes on allowlisted tags are preserved.
        $mp   = new MarkdownParser();
        $html = $mp->parse('<div class="x">hello</div>', allowRawHtml: true);

        $this->assertStringContainsString('<div class="x">hello</div>', $html);
    }

    public function testRawHtmlBlockNotWrappedInParagraph(): void
    {
        // A block HTML node must NOT be wrapped in <p> tags — in either mode.
        // With allowRawHtml: true — sanitizer pass-through, still no <p> wrapper.
        $mp   = new MarkdownParser();
        $html = $mp->parse('<div>content</div>', allowRawHtml: true);

        $this->assertStringNotContainsString('<p>', $html);
        $this->assertStringContainsString('<div>content</div>', $html);
    }

    public function testFourSpaceIndentIsNotHtmlBlock(): void
    {
        // CommonMark §4.6: 4+ spaces of indentation = indented code block, not HTML block.
        // The current lexer does not implement indented code blocks, so 4-space indent
        // falls through to PARAGRAPH — but it must NOT be HTML_BLOCK.
        $tokens = $this->lexer->tokenize('    <div>four spaces</div>');

        $this->assertNotSame(TokenType::HTML_BLOCK, $tokens[0]->type);
    }
}
