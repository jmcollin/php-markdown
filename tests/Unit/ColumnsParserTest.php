<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit;

use PhpMarkdown\Lexer\Lexer;
use PhpMarkdown\Node\Block\ColumnsNode;
use PhpMarkdown\Node\Block\FencedCodeNode;
use PhpMarkdown\Node\Block\ListNode;
use PhpMarkdown\Node\Block\ParagraphNode;
use PhpMarkdown\Node\Block\TableNode;
use PhpMarkdown\Parser\Parser;
use PHPUnit\Framework\TestCase;

final class ColumnsParserTest extends TestCase
{
    private Lexer $lexer;
    private Parser $parser;

    protected function setUp(): void
    {
        $this->lexer  = new Lexer();
        $this->parser = new Parser();
    }

    private function parse(string $markdown): array
    {
        return $this->parser->parse($this->lexer->tokenize($markdown))->children;
    }

    // -------------------------------------------------------------------------
    // Basic ColumnsNode construction
    // -------------------------------------------------------------------------

    public function testBasicColumnsNodeConstructed(): void
    {
        $children = $this->parse(":::columns\nLeft\n|||\nRight\n:::");

        $this->assertCount(1, $children);
        $this->assertInstanceOf(ColumnsNode::class, $children[0]);
    }

    public function testColumnsNodeHasCorrectChildTypes(): void
    {
        $children = $this->parse(":::columns\nLeft text\n|||\nRight text\n:::");

        /** @var ColumnsNode $node */
        $node = $children[0];

        $this->assertCount(1, $node->leftChildren);
        $this->assertInstanceOf(ParagraphNode::class, $node->leftChildren[0]);

        $this->assertCount(1, $node->rightChildren);
        $this->assertInstanceOf(ParagraphNode::class, $node->rightChildren[0]);
    }

    // -------------------------------------------------------------------------
    // Block-level children inside columns
    // -------------------------------------------------------------------------

    public function testListInsideColumn(): void
    {
        $children = $this->parse(":::columns\n- item1\n- item2\n|||\nRight\n:::");

        /** @var ColumnsNode $node */
        $node = $children[0];
        $this->assertCount(1, $node->leftChildren);
        $this->assertInstanceOf(ListNode::class, $node->leftChildren[0]);
    }

    public function testFencedCodeInsideColumn(): void
    {
        $children = $this->parse(":::columns\n```php\necho 'hi';\n```\n|||\nRight\n:::");

        /** @var ColumnsNode $node */
        $node = $children[0];
        $this->assertCount(1, $node->leftChildren);
        $this->assertInstanceOf(FencedCodeNode::class, $node->leftChildren[0]);
    }

    public function testGfmTableInsideColumn(): void
    {
        $left = "| Name | Age |\n|------|-----|\n| Alice | 30 |";
        $children = $this->parse(":::columns\n{$left}\n|||\nRight\n:::");

        /** @var ColumnsNode $node */
        $node = $children[0];
        $this->assertCount(1, $node->leftChildren);
        $this->assertInstanceOf(TableNode::class, $node->leftChildren[0]);
    }

    // -------------------------------------------------------------------------
    // Empty columns
    // -------------------------------------------------------------------------

    public function testEmptyLeftColumn(): void
    {
        $children = $this->parse(":::columns\n|||\nRight\n:::");

        /** @var ColumnsNode $node */
        $node = $children[0];
        $this->assertSame([], $node->leftChildren);
        $this->assertNotEmpty($node->rightChildren);
    }

    public function testEmptyRightColumn(): void
    {
        $children = $this->parse(":::columns\nLeft\n|||\n:::");

        /** @var ColumnsNode $node */
        $node = $children[0];
        $this->assertNotEmpty($node->leftChildren);
        $this->assertSame([], $node->rightChildren);
    }

    public function testNoSeparatorSingleColumn(): void
    {
        $children = $this->parse(":::columns\nAll content\n:::");

        /** @var ColumnsNode $node */
        $node = $children[0];
        $this->assertCount(1, $node->leftChildren);
        $this->assertSame([], $node->rightChildren);
    }

    // -------------------------------------------------------------------------
    // Nested :::columns treated as literal text (flat-only nesting)
    // -------------------------------------------------------------------------

    public function testNestedColumnsIsLiteralText(): void
    {
        $inner = ":::columns\nInner\n|||\nNested\n:::";
        $children = $this->parse(":::columns\n{$inner}\n|||\nRight\n:::");

        /** @var ColumnsNode $node */
        $node = $children[0];

        // The inner content must NOT produce a nested ColumnsNode
        foreach ($node->leftChildren as $child) {
            $this->assertNotInstanceOf(ColumnsNode::class, $child);
        }

        // It must produce a ParagraphNode with literal ":::columns" text
        $this->assertCount(1, $node->leftChildren);
        $this->assertInstanceOf(ParagraphNode::class, $node->leftChildren[0]);
    }

    // -------------------------------------------------------------------------
    // Link references defined before :::columns resolve inside columns
    // -------------------------------------------------------------------------

    public function testLinkRefsDefinedBeforeColumnsResolveInsideColumns(): void
    {
        $markdown = "[example]: https://example.com\n:::columns\n[example]\n|||\nRight\n:::";
        $children = $this->parse($markdown);

        /** @var ColumnsNode $node */
        $node = $children[0];

        // If link ref resolves, the paragraph children contain a LinkNode
        $this->assertNotEmpty($node->leftChildren);
        $paragraphContent = $node->leftChildren[0];
        $this->assertInstanceOf(ParagraphNode::class, $paragraphContent);

        // The paragraph must contain at least one child (the resolved link or text)
        $this->assertNotEmpty($paragraphContent->children);
    }

    // -------------------------------------------------------------------------
    // Columns node among sibling blocks
    // -------------------------------------------------------------------------

    public function testColumnsNodeAmongSiblings(): void
    {
        $children = $this->parse("# Heading\n:::columns\nLeft\n|||\nRight\n:::\nParagraph");

        $this->assertCount(3, $children);
        $this->assertInstanceOf(ColumnsNode::class, $children[1]);
    }
}
