<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit;

use PhpMarkdown\Node\Inline\CodeNode;
use PhpMarkdown\Node\Inline\EmphasisNode;
use PhpMarkdown\Node\Inline\StrikethroughNode;
use PhpMarkdown\Node\Inline\StrongNode;
use PhpMarkdown\Node\Inline\TextNode;
use PhpMarkdown\Parser\InlineParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for backslash escape handling in InlineParser (CommonMark §2.4).
 *
 * All tests drive InlineParser::parse() directly — no MarkdownParser involvement.
 * Hard line break (\<newline>) is intentionally not tested here: the Lexer strips
 * trailing backslashes before InlineParser sees the text (Lexer.php lines 384–391).
 * That boundary is covered at integration level in MarkdownParserTest.
 */
final class InlineParserBackslashEscapeTest extends TestCase
{
    private InlineParser $parser;

    protected function setUp(): void
    {
        $this->parser = new InlineParser();
    }

    // ── Core contract: escaped punctuation suppresses inline syntax ───────────

    public function testEscapedAsteriskSuppressesEmphasis(): void
    {
        // \*not emphasis\* must not produce EmphasisNode — asterisks become TextNodes.
        $nodes = $this->parser->parse('\\*not emphasis\\*');

        $this->assertCount(3, $nodes);
        $this->assertInstanceOf(TextNode::class, $nodes[0]);
        $this->assertSame('*', $nodes[0]->text);
        $this->assertInstanceOf(TextNode::class, $nodes[1]);
        $this->assertSame('not emphasis', $nodes[1]->text);
        $this->assertInstanceOf(TextNode::class, $nodes[2]);
        $this->assertSame('*', $nodes[2]->text);
    }

    public function testEscapedUnderscoreSuppressesEmphasis(): void
    {
        $nodes = $this->parser->parse('\\_not\\_');

        $this->assertCount(3, $nodes);
        $this->assertInstanceOf(TextNode::class, $nodes[0]);
        $this->assertSame('_', $nodes[0]->text);
        $this->assertInstanceOf(TextNode::class, $nodes[2]);
        $this->assertSame('_', $nodes[2]->text);

        // Confirm no EmphasisNode anywhere in the result.
        foreach ($nodes as $node) {
            $this->assertNotInstanceOf(EmphasisNode::class, $node);
        }
    }

    public function testEscapedDoubleAsteriskSuppressesStrong(): void
    {
        // \*\*not bold\*\* — each * is individually escaped.
        $nodes = $this->parser->parse('\\*\\*not bold\\*\\*');

        foreach ($nodes as $node) {
            $this->assertNotInstanceOf(StrongNode::class, $node);
        }

        // First two nodes must be literal * TextNodes.
        $this->assertInstanceOf(TextNode::class, $nodes[0]);
        $this->assertSame('*', $nodes[0]->text);
        $this->assertInstanceOf(TextNode::class, $nodes[1]);
        $this->assertSame('*', $nodes[1]->text);
    }

    public function testEscapedBacktickSuppressesCodeSpan(): void
    {
        // \`not code\` — backticks become TextNodes, no CodeNode is opened.
        $nodes = $this->parser->parse('\\`not code\\`');

        $this->assertCount(3, $nodes);
        $this->assertInstanceOf(TextNode::class, $nodes[0]);
        $this->assertSame('`', $nodes[0]->text);
        $this->assertInstanceOf(TextNode::class, $nodes[1]);
        $this->assertSame('not code', $nodes[1]->text);
        $this->assertInstanceOf(TextNode::class, $nodes[2]);
        $this->assertSame('`', $nodes[2]->text);

        foreach ($nodes as $node) {
            $this->assertNotInstanceOf(CodeNode::class, $node);
        }
    }

    public function testEscapedBracketSuppressesLink(): void
    {
        // \[not a link\](url) — brackets become TextNodes, no LinkNode is produced.
        $nodes = $this->parser->parse('\\[not a link\\](url)');

        foreach ($nodes as $node) {
            $this->assertNotInstanceOf(\PhpMarkdown\Node\Inline\LinkNode::class, $node);
        }

        $this->assertInstanceOf(TextNode::class, $nodes[0]);
        $this->assertSame('[', $nodes[0]->text);
    }

    public function testEscapedBackslashProducesLiteralBackslash(): void
    {
        // \\ → one TextNode containing a single backslash.
        $nodes = $this->parser->parse('\\\\');

        $this->assertCount(1, $nodes);
        $this->assertInstanceOf(TextNode::class, $nodes[0]);
        $this->assertSame('\\', $nodes[0]->text);
    }

    public function testEscapedTildeSuppressesStrikethrough(): void
    {
        $nodes = $this->parser->parse('\\~~not deleted\\~~');

        foreach ($nodes as $node) {
            $this->assertNotInstanceOf(StrikethroughNode::class, $node);
        }

        $this->assertInstanceOf(TextNode::class, $nodes[0]);
        $this->assertSame('~', $nodes[0]->text);
    }

    // ── Non-escapable characters — backslash must be preserved ───────────────

    public function testBackslashBeforeLetterIsLiteralBackslashAndLetter(): void
    {
        // \a — backslash is not an escape; both chars end up in the buffer as one TextNode.
        $nodes = $this->parser->parse('\\a');

        $this->assertCount(1, $nodes);
        $this->assertInstanceOf(TextNode::class, $nodes[0]);
        $this->assertSame('\\a', $nodes[0]->text);
    }

    public function testBackslashBeforeDigitIsLiteralBackslashAndDigit(): void
    {
        $nodes = $this->parser->parse('\\1');

        $this->assertCount(1, $nodes);
        $this->assertInstanceOf(TextNode::class, $nodes[0]);
        $this->assertSame('\\1', $nodes[0]->text);
    }

    public function testBackslashBeforeSpaceIsLiteralBackslashAndSpace(): void
    {
        $nodes = $this->parser->parse('\\ ');

        $this->assertCount(1, $nodes);
        $this->assertInstanceOf(TextNode::class, $nodes[0]);
        $this->assertSame('\\ ', $nodes[0]->text);
    }

    public function testTrailingBackslashAtEndOfInputIsLiteral(): void
    {
        // foo\ — lone trailing backslash is buffered as literal '\'.
        $nodes = $this->parser->parse('foo\\');

        // The backslash appends to the same buffer as 'foo', producing one TextNode.
        $this->assertCount(1, $nodes);
        $this->assertInstanceOf(TextNode::class, $nodes[0]);
        $this->assertSame('foo\\', $nodes[0]->text);
    }

    // ── Consecutive escapes ───────────────────────────────────────────────────

    public function testDoubleBackslashProducesOneLiteralBackslash(): void
    {
        $nodes = $this->parser->parse('\\\\');

        $this->assertCount(1, $nodes);
        $this->assertInstanceOf(TextNode::class, $nodes[0]);
        $this->assertSame('\\', $nodes[0]->text);
    }

    public function testThreeBackslashesBeforeAsterisk(): void
    {
        // \\\ * → escaped-backslash (\) then escaped-asterisk (*): TextNode('\') + TextNode('*').
        // No EmphasisNode must be produced.
        $nodes = $this->parser->parse('\\\\\\*');

        $this->assertCount(2, $nodes);
        $this->assertInstanceOf(TextNode::class, $nodes[0]);
        $this->assertSame('\\', $nodes[0]->text);
        $this->assertInstanceOf(TextNode::class, $nodes[1]);
        $this->assertSame('*', $nodes[1]->text);

        foreach ($nodes as $node) {
            $this->assertNotInstanceOf(EmphasisNode::class, $node);
        }
    }

    public function testFourBackslashesTwoLiteralBackslashes(): void
    {
        // \\\\ → two escaped backslashes → two TextNode('\') that may be merged into one by
        // flushBuffer (they are not — each valid escape flushes the buffer first).
        $nodes = $this->parser->parse('\\\\\\\\');

        // Each \\ produces a TextNode('\') via flushBuffer + TextNode($next).
        // Second \\ also hits the escapable path: flush (empty buffer) → TextNode('\').
        $this->assertCount(2, $nodes);
        $this->assertInstanceOf(TextNode::class, $nodes[0]);
        $this->assertSame('\\', $nodes[0]->text);
        $this->assertInstanceOf(TextNode::class, $nodes[1]);
        $this->assertSame('\\', $nodes[1]->text);
    }

    public function testEscapedBackslashFollowedByAsteriskTriggersEmphasis(): void
    {
        // \\*italic* → escaped backslash (TextNode '\') then *italic* as EmphasisNode.
        $nodes = $this->parser->parse('\\\\*italic*');

        $this->assertCount(2, $nodes);
        $this->assertInstanceOf(TextNode::class, $nodes[0]);
        $this->assertSame('\\', $nodes[0]->text);
        $this->assertInstanceOf(EmphasisNode::class, $nodes[1]);
        $this->assertInstanceOf(TextNode::class, $nodes[1]->children[0]);
        $this->assertSame('italic', $nodes[1]->children[0]->text);
    }

    // ── All 32 CommonMark §2.4 escapable ASCII punctuation characters ─────────

    /** @return array<string, array{string}> */
    public static function escapableCharProvider(): array
    {
        // The full set of 32 ASCII punctuation characters per CommonMark §2.4.
        $chars = str_split('!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~');
        $cases = [];
        foreach ($chars as $ch) {
            $cases[$ch] = [$ch];
        }
        return $cases;
    }

    #[DataProvider('escapableCharProvider')]
    public function testAllEscapableCharsProduceLiteralTextNode(string $char): void
    {
        // \X (backslash + escapable char) must produce exactly one TextNode containing X.
        $nodes = $this->parser->parse('\\' . $char);

        $this->assertCount(1, $nodes);
        $this->assertInstanceOf(TextNode::class, $nodes[0]);
        $this->assertSame($char, $nodes[0]->text);
    }

    // ── No escape inside code span ────────────────────────────────────────────

    public function testBackslashInsideCodeSpanIsLiteralNotAnEscape(): void
    {
        // `\*still code\*` — the code span captures everything between backticks verbatim.
        $nodes = $this->parser->parse('`\\*still code\\*`');

        $this->assertCount(1, $nodes);
        $this->assertInstanceOf(CodeNode::class, $nodes[0]);
        $this->assertSame('\\*still code\\*', $nodes[0]->code);
    }

    // ── Mixed context: escape adjacent to real inline syntax ─────────────────

    public function testEscapeInsidePrecedingTextFlushesBufferCorrectly(): void
    {
        // "foo\*bar" — 'foo' is in buffer when escape fires; flushBuffer must emit it first.
        $nodes = $this->parser->parse('foo\\*bar');

        $this->assertCount(3, $nodes);
        $this->assertInstanceOf(TextNode::class, $nodes[0]);
        $this->assertSame('foo', $nodes[0]->text);
        $this->assertInstanceOf(TextNode::class, $nodes[1]);
        $this->assertSame('*', $nodes[1]->text);
        $this->assertInstanceOf(TextNode::class, $nodes[2]);
        $this->assertSame('bar', $nodes[2]->text);
    }

    public function testEscapedAmpersandDoesNotTriggerEntityParsing(): void
    {
        // \& — backslash is processed first; & becomes a plain TextNode, not HtmlEntityNode.
        $nodes = $this->parser->parse('\\&amp;');

        // \& → TextNode('&'), then 'amp;' hits the catch-all as literal text.
        // The '&' emitted by escape is a TextNode, not an entity.
        $this->assertInstanceOf(TextNode::class, $nodes[0]);
        $this->assertSame('&', $nodes[0]->text);

        foreach ($nodes as $node) {
            $this->assertNotInstanceOf(\PhpMarkdown\Node\Inline\HtmlEntityNode::class, $node);
        }
    }

    public function testEscapedLessThanDoesNotTriggerRawHtmlParsing(): void
    {
        // \<span> — the backslash escape fires first; '<' becomes a TextNode.
        $nodes = $this->parser->parse('\\<span>');

        $this->assertInstanceOf(TextNode::class, $nodes[0]);
        $this->assertSame('<', $nodes[0]->text);

        foreach ($nodes as $node) {
            $this->assertNotInstanceOf(\PhpMarkdown\Node\Inline\RawHtmlInlineNode::class, $node);
        }
    }
}
