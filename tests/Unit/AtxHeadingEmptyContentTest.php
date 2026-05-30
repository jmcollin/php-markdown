<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit;

use PhpMarkdown\Lexer\Lexer;
use PhpMarkdown\Lexer\TokenType;
use PhpMarkdown\Parser\Parser;
use PhpMarkdown\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ATX headings with empty content (CommonMark §4.2).
 *
 * A heading marker followed by spaces only must produce a HEADING token
 * with empty content, and render as <hN></hN>.
 */
final class AtxHeadingEmptyContentTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Token-level tests
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{string, int}>
     */
    public static function emptyHeadingProvider(): array
    {
        return [
            'h1 trailing space'  => ['# ', 1],
            'h2 trailing space'  => ['## ', 2],
            'h6 trailing space'  => ['###### ', 6],
            'h1 closing hashes'  => ['# ###', 1],
        ];
    }

    #[DataProvider('emptyHeadingProvider')]
    public function testEmptyHeadingToken(string $input, int $expectedLevel): void
    {
        $lexer  = new Lexer();
        $tokens = $lexer->tokenize($input);

        $headingTokens = array_values(array_filter(
            $tokens,
            static fn($t) => $t->type === TokenType::HEADING
        ));

        self::assertCount(1, $headingTokens, "Expected exactly one HEADING token for input: {$input}");
        self::assertSame($expectedLevel, $headingTokens[0]->meta['level']);
        self::assertSame('', $headingTokens[0]->content, "HEADING content must be empty for input: {$input}");
    }

    public function testNormalHeadingUnaffected(): void
    {
        $lexer  = new Lexer();
        $tokens = $lexer->tokenize('# Hello');

        $headingTokens = array_values(array_filter(
            $tokens,
            static fn($t) => $t->type === TokenType::HEADING
        ));

        self::assertCount(1, $headingTokens);
        self::assertSame(1, $headingTokens[0]->meta['level']);
        self::assertSame('Hello', $headingTokens[0]->content);
    }

    public function testNoSpaceAfterHashIsNotAHeading(): void
    {
        $lexer  = new Lexer();
        $tokens = $lexer->tokenize('#foo');

        $hasHeading = array_reduce(
            $tokens,
            static fn(bool $carry, $t) => $carry || $t->type === TokenType::HEADING,
            false
        );

        self::assertFalse($hasHeading, '#foo must not produce a HEADING token');
    }

    // -------------------------------------------------------------------------
    // Render-level tests
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function renderEmptyHeadingProvider(): array
    {
        return [
            'h1 trailing space'  => ['# ',      '<h1></h1>'],
            'h2 trailing space'  => ['## ',      '<h2></h2>'],
            'h6 trailing space'  => ['###### ',  '<h6></h6>'],
            'h1 closing hashes'  => ['# ###',    '<h1></h1>'],
            '# Hello regression' => ['# Hello',  '<h1>Hello</h1>'],
            '#foo not a heading' => ['#foo',      '<p>#foo</p>'],
        ];
    }

    #[DataProvider('renderEmptyHeadingProvider')]
    public function testRendersEmptyHtml(string $input, string $expectedHtml): void
    {
        $lexer    = new Lexer();
        $parser   = new Parser();
        $renderer = new HtmlRenderer();

        $tokens = $lexer->tokenize($input);
        $ast    = $parser->parse($tokens);
        $actual = trim($renderer->render($ast));

        self::assertSame($expectedHtml, $actual);
    }
}
