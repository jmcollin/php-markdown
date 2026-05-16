<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit;

use PhpMarkdown\Lexer\Lexer;
use PhpMarkdown\Lexer\TokenType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LexerTest extends TestCase
{
    private Lexer $lexer;

    protected function setUp(): void
    {
        $this->lexer = new Lexer();
    }

    public function testEmptyInputReturnsNoTokens(): void
    {
        $this->assertSame([], $this->lexer->tokenize(''));
    }

    #[DataProvider('headingProvider')]
    public function testHeadingDetected(string $input, int $level, string $content): void
    {
        $tokens = $this->lexer->tokenize($input);

        $this->assertCount(1, $tokens);
        $this->assertSame(TokenType::HEADING, $tokens[0]->type);
        $this->assertSame($level, $tokens[0]->meta['level']);
        $this->assertSame($content, $tokens[0]->content);
    }

    /** @return array<string, array{string, int, string}> */
    public static function headingProvider(): array
    {
        return [
            'h1' => ['# Title',    1, 'Title'],
            'h2' => ['## Title',   2, 'Title'],
            'h3' => ['### Title',  3, 'Title'],
            'h4' => ['#### Title', 4, 'Title'],
            'h5' => ['##### Title', 5, 'Title'],
            'h6' => ['###### Title', 6, 'Title'],
        ];
    }

    public function testHeadingWithoutSpaceIsParagraph(): void
    {
        $tokens = $this->lexer->tokenize('##nospace');

        $this->assertSame(TokenType::PARAGRAPH, $tokens[0]->type);
    }

    public function testFencedCodeBlockWithLanguage(): void
    {
        $tokens = $this->lexer->tokenize("```php\necho 'x';\n```");

        $this->assertCount(1, $tokens);
        $this->assertSame(TokenType::FENCED_CODE, $tokens[0]->type);
        $this->assertSame('php', $tokens[0]->meta['language']);
        $this->assertSame("echo 'x';", $tokens[0]->content);
    }

    public function testUnclosedFencedCodeBlockEmitted(): void
    {
        $tokens = $this->lexer->tokenize("```\norphan");

        $this->assertCount(1, $tokens);
        $this->assertSame(TokenType::FENCED_CODE, $tokens[0]->type);
        $this->assertSame('orphan', $tokens[0]->content);
    }

    public function testUnorderedListItems(): void
    {
        $tokens = $this->lexer->tokenize("- one\n- two");

        $this->assertCount(2, $tokens);
        $this->assertSame(TokenType::LIST_ITEM, $tokens[0]->type);
        $this->assertFalse($tokens[0]->meta['ordered']);
        $this->assertSame('one', $tokens[0]->content);
        $this->assertSame('two', $tokens[1]->content);
    }

    public function testOrderedListItems(): void
    {
        $tokens = $this->lexer->tokenize("1. first\n2. second");

        $this->assertSame(TokenType::LIST_ITEM, $tokens[0]->type);
        $this->assertTrue($tokens[0]->meta['ordered']);
    }

    public function testBlockquote(): void
    {
        $tokens = $this->lexer->tokenize('> quote');

        $this->assertSame(TokenType::BLOCKQUOTE, $tokens[0]->type);
        $this->assertSame('quote', $tokens[0]->content);
        $this->assertSame(1, $tokens[0]->meta['level']);
    }

    public function testNestedBlockquoteLevel(): void
    {
        $tokens = $this->lexer->tokenize('>> deep');

        $this->assertSame(2, $tokens[0]->meta['level']);
    }

    // ── Blockquote level detection ────────────────────────────────────────────

    public function testBlockquoteLevelOne(): void
    {
        $tokens = $this->lexer->tokenize('> hello');

        $this->assertSame(TokenType::BLOCKQUOTE, $tokens[0]->type);
        $this->assertSame(1, $tokens[0]->meta['level']);
        $this->assertSame('hello', $tokens[0]->content);
    }

    public function testBlockquoteLevelTwo(): void
    {
        $tokens = $this->lexer->tokenize('> > text');

        $this->assertSame(TokenType::BLOCKQUOTE, $tokens[0]->type);
        $this->assertSame(2, $tokens[0]->meta['level']);
        $this->assertSame('text', $tokens[0]->content);
    }

    public function testBlockquoteLevelThree(): void
    {
        $tokens = $this->lexer->tokenize('> > > text');

        $this->assertSame(TokenType::BLOCKQUOTE, $tokens[0]->type);
        $this->assertSame(3, $tokens[0]->meta['level']);
    }

    public function testBlockquoteCompactDoubleMarker(): void
    {
        $tokens = $this->lexer->tokenize('>> text');

        $this->assertSame(TokenType::BLOCKQUOTE, $tokens[0]->type);
        $this->assertSame(2, $tokens[0]->meta['level']);
    }

    public function testBlockquoteNoSpaceAfterSoleMarker(): void
    {
        $tokens = $this->lexer->tokenize('>text');

        $this->assertSame(TokenType::BLOCKQUOTE, $tokens[0]->type);
        $this->assertSame(1, $tokens[0]->meta['level']);
        $this->assertSame('text', $tokens[0]->content);
    }

    public function testBlockquoteNoSpaceAfterInnerMarker(): void
    {
        // "> >text" (no space after inner >) must still parse correctly
        $tokens = $this->lexer->tokenize('> >text');

        $this->assertSame(TokenType::BLOCKQUOTE, $tokens[0]->type);
        $this->assertSame(2, $tokens[0]->meta['level']);
        $this->assertSame('text', $tokens[0]->content);
    }

    public function testBlockquoteTrailingSpacesInContentAreTrimmed(): void
    {
        $tokens = $this->lexer->tokenize('>   lots of spaces   ');

        $this->assertSame(TokenType::BLOCKQUOTE, $tokens[0]->type);
        $this->assertSame('lots of spaces', $tokens[0]->content);
    }

    public function testHorizontalRuleVariants(): void
    {
        foreach (['---', '***', '___'] as $hr) {
            $tokens = $this->lexer->tokenize($hr);
            $this->assertSame(TokenType::HORIZONTAL_RULE, $tokens[0]->type, "HR: {$hr}");
        }
    }

    public function testBlankLineProcducesBlankToken(): void
    {
        $tokens = $this->lexer->tokenize("text\n\ntext2");

        $this->assertSame(TokenType::BLANK, $tokens[1]->type);
    }

    public function testParagraphFallback(): void
    {
        $tokens = $this->lexer->tokenize('plain text');

        $this->assertSame(TokenType::PARAGRAPH, $tokens[0]->type);
        $this->assertSame('plain text', $tokens[0]->content);
    }

    public function testCrlfLineEndingsStripped(): void
    {
        $tokens = $this->lexer->tokenize("## Title\r\n");

        $this->assertSame(TokenType::HEADING, $tokens[0]->type);
        $this->assertSame('Title', $tokens[0]->content);
    }

    public function testFencedCodeBlockWithHyphenatedLanguage(): void
    {
        $tokens = $this->lexer->tokenize("```objective-c\nNSLog(@\"hi\");\n```");

        $this->assertSame(TokenType::FENCED_CODE, $tokens[0]->type);
        $this->assertSame('objective-c', $tokens[0]->meta['language']);
    }

    public function testImageWithTitleParsed(): void
    {
        $mp = new \PhpMarkdown\MarkdownParser();
        $html = $mp->parse('![alt](img.png "tooltip")');

        $this->assertStringContainsString('src="img.png"', $html);
        $this->assertStringContainsString('title="tooltip"', $html);
        $this->assertStringContainsString('alt="alt"', $html);
    }

    // ── Nested list depth ────────────────────────────────────────────────────

    public function testListItemDepthZero(): void
    {
        $tokens = $this->lexer->tokenize('- item');

        $this->assertSame(0, $tokens[0]->meta['depth']);
    }

    public function testListItemDepthOne(): void
    {
        $tokens = $this->lexer->tokenize('  - item');

        $this->assertSame(1, $tokens[0]->meta['depth']);
    }

    public function testListItemDepthTwo(): void
    {
        $tokens = $this->lexer->tokenize('    - item');

        $this->assertSame(2, $tokens[0]->meta['depth']);
    }

    public function testOrderedListItemDepth(): void
    {
        $tokens = $this->lexer->tokenize('  1. item');

        $this->assertTrue($tokens[0]->meta['ordered']);
        $this->assertSame(1, $tokens[0]->meta['depth']);
    }

    public function testListItemContentUnchanged(): void
    {
        $tokens = $this->lexer->tokenize('  - hello world');

        $this->assertSame('hello world', $tokens[0]->content);
        $this->assertSame(1, $tokens[0]->meta['depth']);
    }

    public function testNestedListTokenDepths(): void
    {
        $tokens = $this->lexer->tokenize("- a\n  - b");

        $this->assertSame(0, $tokens[0]->meta['depth']);
        $this->assertSame(1, $tokens[1]->meta['depth']);
    }

    // ── Task list (checkbox) tokens ──────────────────────────────────────────

    public function testCheckedTaskItemSetsCheckedTrue(): void
    {
        $tokens = $this->lexer->tokenize('- [x] Done');

        $this->assertSame(TokenType::LIST_ITEM, $tokens[0]->type);
        $this->assertTrue($tokens[0]->meta['checked']);
        $this->assertSame('Done', $tokens[0]->content);
    }

    public function testUncheckedTaskItemSetsCheckedFalse(): void
    {
        $tokens = $this->lexer->tokenize('- [ ] Todo');

        $this->assertFalse($tokens[0]->meta['checked']);
        $this->assertSame('Todo', $tokens[0]->content);
    }

    public function testPlainListItemCheckedIsNull(): void
    {
        $tokens = $this->lexer->tokenize('- plain');

        $this->assertNull($tokens[0]->meta['checked']);
    }

    public function testUppercaseXTaskItemIsChecked(): void
    {
        $tokens = $this->lexer->tokenize('- [X] Done');

        $this->assertTrue($tokens[0]->meta['checked']);
        $this->assertSame('Done', $tokens[0]->content);
    }

    public function testOrderedTaskItemChecked(): void
    {
        $tokens = $this->lexer->tokenize('1. [x] first');

        $this->assertTrue($tokens[0]->meta['checked']);
        $this->assertSame('first', $tokens[0]->content);
    }

    // ── Link definitions ─────────────────────────────────────────────────────

    #[DataProvider('linkDefinitionProvider')]
    public function testLinkDefinitionDetected(
        string $input,
        TokenType $expectedType,
        ?string $label,
        ?string $href,
        ?string $title,
    ): void {
        $tokens = $this->lexer->tokenize($input);

        $this->assertCount(1, $tokens);
        $this->assertSame($expectedType, $tokens[0]->type);

        if ($label !== null) {
            $this->assertSame($label, $tokens[0]->meta['label']);
        }
        if ($href !== null) {
            $this->assertSame($href, $tokens[0]->meta['href']);
        }
        if ($title !== null) {
            $this->assertSame($title, $tokens[0]->meta['title']);
        }
    }

    /** @return array<string, array{string, TokenType, ?string, ?string, ?string}> */
    public static function linkDefinitionProvider(): array
    {
        return [
            'basic definition' => [
                '[foo]: https://example.com',
                TokenType::LINK_DEFINITION,
                'foo',
                'https://example.com',
                null,
            ],
            'definition with title' => [
                '[foo]: https://example.com "My title"',
                TokenType::LINK_DEFINITION,
                'foo',
                'https://example.com',
                'My title',
            ],
            'label with spaces' => [
                '[foo bar]: https://example.com',
                TokenType::LINK_DEFINITION,
                'foo bar',
                'https://example.com',
                null,
            ],
            'label mixed case preserved' => [
                '[FOO]: https://example.com',
                TokenType::LINK_DEFINITION,
                'FOO',
                'https://example.com',
                null,
            ],
            'unsafe url still emits link definition' => [
                '[foo]: javascript:alert(1)',
                TokenType::LINK_DEFINITION,
                'foo',
                'javascript:alert(1)',
                null,
            ],
        ];
    }

    public function testLinkDefinitionWithoutColonIsParagraph(): void
    {
        $tokens = $this->lexer->tokenize('[foo]');

        $this->assertCount(1, $tokens);
        $this->assertSame(TokenType::PARAGRAPH, $tokens[0]->type);
    }

    public function testLinkDefinitionTitleIsNullWhenAbsent(): void
    {
        $tokens = $this->lexer->tokenize('[foo]: https://example.com');

        $this->assertNull($tokens[0]->meta['title']);
    }
}
