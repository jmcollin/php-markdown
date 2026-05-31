<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit;

use PhpMarkdown\Lexer\Lexer;
use PhpMarkdown\Lexer\TokenType;
use PhpMarkdown\Parser\Parser;
use PhpMarkdown\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

final class IndentedCodeBlockTest extends TestCase
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

    private function render(string $markdown): string
    {
        return $this->renderer->render(
            $this->parser->parse($this->lexer->tokenize($markdown))
        );
    }

    /** @return \PhpMarkdown\Lexer\Token[] */
    private function tokenize(string $markdown): array
    {
        return $this->lexer->tokenize($markdown);
    }

    // --- Lexer-level tests ---

    public function testSingleIndentedLineProducesIndentedCodeToken(): void
    {
        $tokens = $this->tokenize('    hello world');

        $this->assertCount(1, $tokens);
        $this->assertSame(TokenType::INDENTED_CODE, $tokens[0]->type);
        $this->assertSame("hello world\n", $tokens[0]->content);
    }

    public function testTabIndentedLineProducesIndentedCodeToken(): void
    {
        $tokens = $this->tokenize("\thello world");

        $this->assertCount(1, $tokens);
        $this->assertSame(TokenType::INDENTED_CODE, $tokens[0]->type);
        $this->assertSame("hello world\n", $tokens[0]->content);
    }

    public function testConsecutiveIndentedLinesFormSingleToken(): void
    {
        $tokens = $this->tokenize("    line A\n    line B");

        $indentedTokens = array_filter($tokens, fn($t) => $t->type === TokenType::INDENTED_CODE);
        $this->assertCount(1, $indentedTokens);
    }

    public function testBlankLineInsideBlockIsPreservedInToken(): void
    {
        // Blank line between two indented lines should be kept in the token content.
        $tokens = $this->tokenize("    first\n\n    second");

        $indentedTokens = array_values(array_filter($tokens, fn($t) => $t->type === TokenType::INDENTED_CODE));
        $this->assertCount(1, $indentedTokens);
        $this->assertSame("first\n\nsecond\n", $indentedTokens[0]->content);
    }

    public function testTrailingBlankLinesInsideBlockStrippedAtLexerLevel(): void
    {
        // Trailing blank lines after the last indented line must NOT be included in the token.
        $tokens = $this->tokenize("    content\n\n\n");

        $indentedTokens = array_values(array_filter($tokens, fn($t) => $t->type === TokenType::INDENTED_CODE));
        $this->assertCount(1, $indentedTokens);
        $this->assertSame("content\n", $indentedTokens[0]->content);
    }

    public function testIndentedBlockCannotInterruptParagraph(): void
    {
        // An indented line that follows a paragraph line must NOT produce a code block.
        $html = $this->render("paragraph text\n    indented");

        $this->assertStringContainsString('<p>', $html);
        $this->assertStringNotContainsString('<pre>', $html);
    }

    public function testBlankLineSeparatesParagraphFromIndentedBlock(): void
    {
        // A blank line between paragraph and indented line allows the code block.
        $html = $this->render("paragraph text\n\n    code line");

        $this->assertStringContainsString('<p>', $html);
        $this->assertStringContainsString('<pre><code>', $html);
    }

    // --- Renderer-level tests ---

    public function testBasicIndentedBlockRendering(): void
    {
        $html = $this->render('    hello world');

        $this->assertSame('<pre><code>hello world' . "\n" . "</code></pre>\n", $html);
    }

    public function testConsecutiveIndentedLinesFormSingleBlock(): void
    {
        $html = $this->render("    line A\n    line B");

        // Must produce exactly one <pre> element.
        $this->assertSame(1, substr_count($html, '<pre>'));
        $this->assertStringContainsString("line A\nline B\n", $html);
    }

    public function testBlankLineInsideBlockIsPreserved(): void
    {
        $html = $this->render("    first\n\n    second");

        $this->assertSame('<pre><code>first' . "\n\n" . 'second' . "\n" . "</code></pre>\n", $html);
    }

    public function testTrailingBlankLinesInsideBlockStripped(): void
    {
        $html = $this->render("    content\n\n\n");

        $this->assertSame('<pre><code>content' . "\n" . "</code></pre>\n", $html);
    }

    public function testHtmlCharactersEscapedInContent(): void
    {
        $html = $this->render('    <b>&</b>');

        $this->assertStringContainsString('&lt;b&gt;&amp;&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>', $html);
    }

    public function testIndentationPrefixStripped(): void
    {
        // The four leading spaces must be stripped from the output.
        $html = $this->render('    indented line');

        $this->assertStringNotContainsString('    indented line', $html);
        $this->assertStringContainsString('indented line', $html);
    }

    public function testNonIndentedLineAfterBlockClosesIt(): void
    {
        // A non-indented, non-blank line closes the block and starts a new paragraph.
        $html = $this->render("    code here\nnormal paragraph");

        $this->assertStringContainsString('<pre><code>', $html);
        $this->assertStringContainsString('<p>', $html);
        $this->assertStringContainsString('normal paragraph', $html);
    }

    public function testCodeBlockFollowedByParagraph(): void
    {
        $html = $this->render("    code\n\nparagraph");

        $this->assertStringContainsString('<pre><code>code' . "\n" . '</code></pre>', $html);
        $this->assertStringContainsString('<p>paragraph</p>', $html);
    }

    public function testCodeBlockPrecededByHeading(): void
    {
        $html = $this->render("# Heading\n\n    code line");

        $this->assertStringContainsString('<h1>Heading</h1>', $html);
        $this->assertStringContainsString('<pre><code>code line' . "\n" . '</code></pre>', $html);
    }

    public function testMultipleBlocksWithBlankBetween(): void
    {
        // Two separate indented blocks separated by a non-indented line.
        $html = $this->render("    block one\n\nnot code\n\n    block two");

        $this->assertSame(2, substr_count($html, '<pre>'));
    }

    public function testFiveSpaceIndentPreservesOneLeadingSpace(): void
    {
        // AC-04: 5 spaces → 4 stripped, 1 space remains in content.
        $html = $this->render('     foo');

        $this->assertSame('<pre><code> foo' . "\n" . "</code></pre>\n", $html);
    }

    public function testNoClassAttributeOnCodeElement(): void
    {
        // AC-08: indented code blocks carry no class= attribute on <code>.
        $html = $this->render('    php');

        $this->assertStringNotContainsString('class=', $html);
        $this->assertSame('<pre><code>php' . "\n" . "</code></pre>\n", $html);
    }
}
