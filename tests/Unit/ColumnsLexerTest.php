<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit;

use PhpMarkdown\Lexer\Lexer;
use PhpMarkdown\Lexer\TokenType;
use PHPUnit\Framework\TestCase;

final class ColumnsLexerTest extends TestCase
{
    private Lexer $lexer;

    protected function setUp(): void
    {
        $this->lexer = new Lexer();
    }

    // -------------------------------------------------------------------------
    // Basic emission
    // -------------------------------------------------------------------------

    public function testBasicColumnsContainerEmitted(): void
    {
        $tokens = $this->lexer->tokenize(":::columns\nLeft content\n|||\nRight content\n:::");

        $this->assertCount(1, $tokens);
        $this->assertSame(TokenType::COLUMNS_CONTAINER, $tokens[0]->type);
        $this->assertSame('Left content', $tokens[0]->meta['left_raw']);
        $this->assertSame('Right content', $tokens[0]->meta['right_raw']);
    }

    public function testColumnsContainerHasEmptyContentField(): void
    {
        $tokens = $this->lexer->tokenize(":::columns\nA\n|||\nB\n:::");

        $this->assertSame('', $tokens[0]->content);
    }

    // -------------------------------------------------------------------------
    // Separator behaviour
    // -------------------------------------------------------------------------

    public function testMissingSeparatorPutsAllContentInLeftRaw(): void
    {
        $tokens = $this->lexer->tokenize(":::columns\nAll content\n:::");

        $this->assertCount(1, $tokens);
        $this->assertSame(TokenType::COLUMNS_CONTAINER, $tokens[0]->type);
        $this->assertSame('All content', $tokens[0]->meta['left_raw']);
        $this->assertSame('', $tokens[0]->meta['right_raw']);
    }

    public function testOnlyFirstSeparatorSplits(): void
    {
        $tokens = $this->lexer->tokenize(":::columns\nLeft\n|||\nMiddle\n|||\nRight\n:::");

        $this->assertCount(1, $tokens);
        $this->assertSame('Left', $tokens[0]->meta['left_raw']);
        // Second ||| and "Right" are content of the right column
        $this->assertStringContainsString('Middle', $tokens[0]->meta['right_raw']);
        $this->assertStringContainsString('Right', $tokens[0]->meta['right_raw']);
    }

    public function testSeparatorWithSurroundingSpacesIsNotRecognised(): void
    {
        // " ||| " (with leading/trailing space) must NOT be treated as separator
        $tokens = $this->lexer->tokenize(":::columns\nLeft\n ||| \nRight\n:::");

        $this->assertCount(1, $tokens);
        // Without a real separator, all lines are in left_raw
        $this->assertSame("Left\n ||| \nRight", $tokens[0]->meta['left_raw']);
        $this->assertSame('', $tokens[0]->meta['right_raw']);
    }

    // -------------------------------------------------------------------------
    // Label matching
    // -------------------------------------------------------------------------

    public function testTrailingSpacesOnLabelRecognised(): void
    {
        $tokens = $this->lexer->tokenize(":::columns   \nLeft\n|||\nRight\n:::");

        $this->assertCount(1, $tokens);
        $this->assertSame(TokenType::COLUMNS_CONTAINER, $tokens[0]->type);
    }

    public function testUppercaseLabelRecognised(): void
    {
        $tokens = $this->lexer->tokenize(":::COLUMNS\nLeft\n|||\nRight\n:::");

        $this->assertCount(1, $tokens);
        $this->assertSame(TokenType::COLUMNS_CONTAINER, $tokens[0]->type);
    }

    // -------------------------------------------------------------------------
    // EOF flush
    // -------------------------------------------------------------------------

    public function testUnclosedBlockAtEofEmitsToken(): void
    {
        $tokens = $this->lexer->tokenize(":::columns\nLeft content\n|||\nRight content");

        $this->assertCount(1, $tokens);
        $this->assertSame(TokenType::COLUMNS_CONTAINER, $tokens[0]->type);
        $this->assertSame('Left content', $tokens[0]->meta['left_raw']);
        $this->assertSame('Right content', $tokens[0]->meta['right_raw']);
    }

    public function testUnclosedBlockNoSeparatorAtEof(): void
    {
        $tokens = $this->lexer->tokenize(":::columns\nAll content here");

        $this->assertCount(1, $tokens);
        $this->assertSame('All content here', $tokens[0]->meta['left_raw']);
        $this->assertSame('', $tokens[0]->meta['right_raw']);
    }

    // -------------------------------------------------------------------------
    // Empty columns
    // -------------------------------------------------------------------------

    public function testEmptyLeftColumn(): void
    {
        $tokens = $this->lexer->tokenize(":::columns\n|||\nRight\n:::");

        $this->assertCount(1, $tokens);
        $this->assertSame('', $tokens[0]->meta['left_raw']);
        $this->assertSame('Right', $tokens[0]->meta['right_raw']);
    }

    public function testEmptyRightColumn(): void
    {
        $tokens = $this->lexer->tokenize(":::columns\nLeft\n|||\n:::");

        $this->assertCount(1, $tokens);
        $this->assertSame('Left', $tokens[0]->meta['left_raw']);
        $this->assertSame('', $tokens[0]->meta['right_raw']);
    }

    public function testCompletelyEmptyBlock(): void
    {
        $tokens = $this->lexer->tokenize(":::columns\n:::");

        $this->assertCount(1, $tokens);
        $this->assertSame('', $tokens[0]->meta['left_raw']);
        $this->assertSame('', $tokens[0]->meta['right_raw']);
    }

    // -------------------------------------------------------------------------
    // Regression — ||| outside columns block must NOT produce COLUMNS_CONTAINER
    // -------------------------------------------------------------------------

    public function testPipeTripleOutsideColumnsBlockIsNotColumnsContainer(): void
    {
        $tokens = $this->lexer->tokenize("Some paragraph\n|||\nAnother paragraph");

        foreach ($tokens as $token) {
            $this->assertNotSame(TokenType::COLUMNS_CONTAINER, $token->type);
        }
    }

    public function testPipeTripleOutsideColumnsBlockProducesTableSeparator(): void
    {
        $tokens = $this->lexer->tokenize("|||");

        $this->assertCount(1, $tokens);
        $this->assertSame(TokenType::TABLE_SEPARATOR, $tokens[0]->type);
    }

    // -------------------------------------------------------------------------
    // Negative — wrong labels must not open a columns block
    // -------------------------------------------------------------------------

    public function testColonColonColonAloneIsNotColumnsOpener(): void
    {
        $tokens = $this->lexer->tokenize(":::\nContent\n:::");

        foreach ($tokens as $token) {
            $this->assertNotSame(TokenType::COLUMNS_CONTAINER, $token->type);
        }
    }

    public function testColonColonColonCodeLabelIsNotColumnsOpener(): void
    {
        $tokens = $this->lexer->tokenize(":::code\nFoo\n|||\nBar\n:::");

        foreach ($tokens as $token) {
            $this->assertNotSame(TokenType::COLUMNS_CONTAINER, $token->type);
        }
    }

    // -------------------------------------------------------------------------
    // Multi-line content is preserved correctly
    // -------------------------------------------------------------------------

    public function testMultilineColumnsContent(): void
    {
        $input = ":::columns\nLine1\nLine2\nLine3\n|||\nA\nB\n:::";
        $tokens = $this->lexer->tokenize($input);

        $this->assertCount(1, $tokens);
        $this->assertSame("Line1\nLine2\nLine3", $tokens[0]->meta['left_raw']);
        $this->assertSame("A\nB", $tokens[0]->meta['right_raw']);
    }

    // -------------------------------------------------------------------------
    // Columns block surrounded by other tokens
    // -------------------------------------------------------------------------

    public function testColumnsBlockAmongOtherTokens(): void
    {
        $input = "# Heading\n:::columns\nLeft\n|||\nRight\n:::\nParagraph";
        $tokens = $this->lexer->tokenize($input);

        $types = array_map(fn($t) => $t->type, $tokens);
        $this->assertContains(TokenType::HEADING, $types);
        $this->assertContains(TokenType::COLUMNS_CONTAINER, $types);
        $this->assertContains(TokenType::PARAGRAPH, $types);
    }
}
