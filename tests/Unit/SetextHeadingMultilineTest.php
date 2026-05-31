<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit;

use PhpMarkdown\Lexer\Lexer;
use PhpMarkdown\Lexer\TokenType;
use PhpMarkdown\Parser\Parser;
use PhpMarkdown\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for setext headings with multiple preceding paragraph lines.
 */
final class SetextHeadingMultilineTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Token-level tests
    // -------------------------------------------------------------------------

    public function testTwoLineH1Token(): void
    {
        $lexer  = new Lexer();
        $tokens = $lexer->tokenize("foo\nbar\n===");

        $headingTokens = array_values(array_filter(
            $tokens,
            static fn($t) => $t->type === TokenType::HEADING,
        ));

        self::assertCount(1, $headingTokens);
        self::assertSame(1, $headingTokens[0]->meta['level']);
        self::assertSame('foo bar', $headingTokens[0]->content);
    }

    public function testThreeLineH2Token(): void
    {
        $lexer  = new Lexer();
        $tokens = $lexer->tokenize("line one\nline two\nline three\n---");

        $headingTokens = array_values(array_filter(
            $tokens,
            static fn($t) => $t->type === TokenType::HEADING,
        ));

        self::assertCount(1, $headingTokens);
        self::assertSame(2, $headingTokens[0]->meta['level']);
        self::assertSame('line one line two line three', $headingTokens[0]->content);
    }

    public function testSingleLineRegressionToken(): void
    {
        $lexer  = new Lexer();
        $tokens = $lexer->tokenize("foo\n===");

        $headingTokens = array_values(array_filter(
            $tokens,
            static fn($t) => $t->type === TokenType::HEADING,
        ));

        self::assertCount(1, $headingTokens);
        self::assertSame(1, $headingTokens[0]->meta['level']);
        self::assertSame('foo', $headingTokens[0]->content);
    }

    public function testBlankBreaksAccumulationTokens(): void
    {
        $lexer  = new Lexer();
        $tokens = $lexer->tokenize("foo\n\nbar\n===");

        $types = array_map(static fn($t) => $t->type, $tokens);

        self::assertContains(TokenType::PARAGRAPH, $types);
        self::assertContains(TokenType::BLANK, $types);
        self::assertContains(TokenType::HEADING, $types);

        $paragraphs = array_values(array_filter($tokens, static fn($t) => $t->type === TokenType::PARAGRAPH));
        $headings   = array_values(array_filter($tokens, static fn($t) => $t->type === TokenType::HEADING));

        self::assertCount(1, $paragraphs);
        self::assertSame('foo', $paragraphs[0]->content);

        self::assertCount(1, $headings);
        self::assertSame(1, $headings[0]->meta['level']);
        self::assertSame('bar', $headings[0]->content);
    }

    public function testInlineMarkupSpansLinesToken(): void
    {
        $lexer  = new Lexer();
        $tokens = $lexer->tokenize("*foo\nbar*\n===");

        $headingTokens = array_values(array_filter(
            $tokens,
            static fn($t) => $t->type === TokenType::HEADING,
        ));

        self::assertCount(1, $headingTokens);
        self::assertSame(1, $headingTokens[0]->meta['level']);
        self::assertSame('*foo bar*', $headingTokens[0]->content);
    }

    public function testAtxHeadingInterruptsPendingLines(): void
    {
        $lexer  = new Lexer();
        $tokens = $lexer->tokenize("foo\n## bar");

        $paragraphs = array_values(array_filter($tokens, static fn($t) => $t->type === TokenType::PARAGRAPH));
        $headings   = array_values(array_filter($tokens, static fn($t) => $t->type === TokenType::HEADING));

        self::assertCount(1, $paragraphs);
        self::assertSame('foo', $paragraphs[0]->content);

        self::assertCount(1, $headings);
        self::assertSame(2, $headings[0]->meta['level']);
        self::assertSame('bar', $headings[0]->content);
    }

    // -------------------------------------------------------------------------
    // Render-level tests
    // -------------------------------------------------------------------------

    private function render(string $markdown): string
    {
        $lexer    = new Lexer();
        $parser   = new Parser();
        $renderer = new HtmlRenderer();

        return trim($renderer->render($parser->parse($lexer->tokenize($markdown))));
    }

    public function testTwoLineH1Renders(): void
    {
        self::assertSame('<h1>foo bar</h1>', $this->render("foo\nbar\n==="));
    }

    public function testThreeLineH2Renders(): void
    {
        self::assertSame('<h2>line one line two line three</h2>', $this->render("line one\nline two\nline three\n---"));
    }

    public function testSingleLineRegressionRenders(): void
    {
        self::assertSame('<h1>foo</h1>', $this->render("foo\n==="));
    }

    public function testBlankBreaksAccumulationRenders(): void
    {
        self::assertSame('<p>foo</p><h1>bar</h1>', $this->render("foo\n\nbar\n==="));
    }

    public function testInlineMarkupSpansLinesRenders(): void
    {
        self::assertSame('<h1><em>foo bar</em></h1>', $this->render("*foo\nbar*\n==="));
    }
}
