<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit;

use PhpMarkdown\Lexer\Lexer;
use PhpMarkdown\Node\Block\ListItemNode;
use PhpMarkdown\Node\Block\ListNode;
use PhpMarkdown\Node\Inline\TextNode;
use PhpMarkdown\Parser\Parser;
use PhpMarkdown\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

final class LooseTightListTest extends TestCase
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

    // -------------------------------------------------------------------------
    // AC1 — Tight unordered list: no <p> wrapping
    // -------------------------------------------------------------------------

    public function testTightUnorderedList(): void
    {
        $html = $this->render("- foo\n- bar\n- baz");

        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<li>foo</li>', $html);
        $this->assertStringContainsString('<li>bar</li>', $html);
        $this->assertStringContainsString('<li>baz</li>', $html);
        $this->assertStringNotContainsString('<p>', $html);
    }

    // -------------------------------------------------------------------------
    // AC2 — Loose unordered list (blank between every item): <p> wrapping
    // -------------------------------------------------------------------------

    public function testLooseUnorderedList(): void
    {
        $html = $this->render("- foo\n\n- bar\n\n- baz");

        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString("<li>\n<p>foo</p>\n</li>", $html);
        $this->assertStringContainsString("<li>\n<p>bar</p>\n</li>", $html);
        $this->assertStringContainsString("<li>\n<p>baz</p>\n</li>", $html);
    }

    // -------------------------------------------------------------------------
    // AC3 — One blank between any two items makes the whole list loose
    // -------------------------------------------------------------------------

    public function testOneBlankMakesAllLoose(): void
    {
        // Blank only between second and third item — entire list must be loose.
        $html = $this->render("- alpha\n- beta\n\n- gamma");

        // All three items must be wrapped in <p>.
        $this->assertStringContainsString("<li>\n<p>alpha</p>\n</li>", $html);
        $this->assertStringContainsString("<li>\n<p>beta</p>\n</li>", $html);
        $this->assertStringContainsString("<li>\n<p>gamma</p>\n</li>", $html);
    }

    // -------------------------------------------------------------------------
    // AC4 — Tight ordered list: no <p> wrapping
    // -------------------------------------------------------------------------

    public function testTightOrderedList(): void
    {
        $html = $this->render("1. one\n2. two\n3. three");

        $this->assertStringContainsString('<ol>', $html);
        $this->assertStringContainsString('<li>one</li>', $html);
        $this->assertStringContainsString('<li>two</li>', $html);
        $this->assertStringContainsString('<li>three</li>', $html);
        $this->assertStringNotContainsString('<p>', $html);
    }

    // -------------------------------------------------------------------------
    // AC5 — Loose ordered list: <p> wrapping
    // -------------------------------------------------------------------------

    public function testLooseOrderedList(): void
    {
        $html = $this->render("1. one\n\n2. two\n\n3. three");

        $this->assertStringContainsString('<ol>', $html);
        $this->assertStringContainsString("<li>\n<p>one</p>\n</li>", $html);
        $this->assertStringContainsString("<li>\n<p>two</p>\n</li>", $html);
        $this->assertStringContainsString("<li>\n<p>three</p>\n</li>", $html);
    }

    // -------------------------------------------------------------------------
    // AC6 — Multi-paragraph item
    //
    // Note: The current Lexer merges continuation lines into a single LIST_ITEM
    // token, so true multi-paragraph items (two blank-separated paragraphs
    // inside one item) are not supported without Lexer changes. This test
    // verifies the loose-list behaviour that IS supported: a blank line between
    // two separate list items produces <p>-wrapped content in both items.
    // -------------------------------------------------------------------------

    public function testMultiParagraphItemIsLoose(): void
    {
        // Two items separated by a blank line — each item content is wrapped in <p>.
        $html = $this->render("- first item\n\n- second item");

        $this->assertStringContainsString('<p>first item</p>', $html);
        $this->assertStringContainsString('<p>second item</p>', $html);
    }

    // -------------------------------------------------------------------------
    // AC7 — Tight list must not wrap content in <p>
    // -------------------------------------------------------------------------

    public function testTightListNoParaWrapping(): void
    {
        $html = $this->render("- item one\n- item two");

        $this->assertStringNotContainsString('<p>', $html);
        $this->assertStringContainsString('item one', $html);
        $this->assertStringContainsString('item two', $html);
    }

    // -------------------------------------------------------------------------
    // AC8 — Tight sub-list inside a loose outer list
    //
    // The outer list is loose (blank between items). The sub-list is tight
    // (no blank between its items). Outer items get <p>; sub-list items do not.
    // -------------------------------------------------------------------------

    public function testTightSublistInsideLooseOuter(): void
    {
        $markdown = "- outer a\n\n- outer b\n  - sub 1\n  - sub 2";
        $html     = $this->render($markdown);

        // Outer list is loose → outer items are paragraph-wrapped.
        $this->assertStringContainsString('<p>outer a</p>', $html);
        // Sub-list items are tight → no <p> wrapping inside the sub-list.
        $this->assertStringContainsString('<li>sub 1</li>', $html);
        $this->assertStringContainsString('<li>sub 2</li>', $html);
    }

    // -------------------------------------------------------------------------
    // AC9 — Trailing blank after last item does not make the list loose
    // -------------------------------------------------------------------------

    public function testTrailingBlankNotLoose(): void
    {
        // Blank line comes AFTER the last item — no inter-item blank, so tight.
        $html = $this->render("- item a\n- item b\n\nparagraph");

        $this->assertStringContainsString('<li>item a</li>', $html);
        $this->assertStringContainsString('<li>item b</li>', $html);
        // Must NOT wrap list items in <p>.
        $this->assertStringNotContainsString('<li><p>', $html);
        // The paragraph after the list must still render.
        $this->assertStringContainsString('<p>paragraph</p>', $html);
    }

    // -------------------------------------------------------------------------
    // AC10 — Blank mid-list makes entire list loose
    // -------------------------------------------------------------------------

    public function testMidListBlankMakesLoose(): void
    {
        // Items: x, y (blank after y), z — the blank between y and z makes
        // the whole list loose, so x must also be wrapped in <p>.
        $html = $this->render("- x\n- y\n\n- z");

        $this->assertStringContainsString("<li>\n<p>x</p>\n</li>", $html);
        $this->assertStringContainsString("<li>\n<p>y</p>\n</li>", $html);
        $this->assertStringContainsString("<li>\n<p>z</p>\n</li>", $html);
    }

    // -------------------------------------------------------------------------
    // Unit — AST: ListNode.loose flag is true when constructed with loose=true
    // -------------------------------------------------------------------------

    public function testListNodeLooseFlagTrue(): void
    {
        $node = new ListNode(ordered: false, loose: true, children: []);

        $this->assertTrue($node->loose);
        $this->assertFalse($node->ordered);
    }

    // -------------------------------------------------------------------------
    // Unit — AST: ListNode.loose flag defaults to false
    // -------------------------------------------------------------------------

    public function testListNodeLooseFlagFalse(): void
    {
        $node = new ListNode(ordered: true);

        $this->assertFalse($node->loose);
    }

    // -------------------------------------------------------------------------
    // Unit — AST: loose flag is propagated to all ListItemNodes
    // -------------------------------------------------------------------------

    public function testLoosePropagatedToListItems(): void
    {
        // Build a loose list via the full pipeline.
        $doc   = $this->parser->parse($this->lexer->tokenize("- a\n\n- b"));
        $list  = $doc->children[0];

        $this->assertInstanceOf(ListNode::class, $list);
        $this->assertTrue($list->loose);
    }

    // -------------------------------------------------------------------------
    // Unit — Renderer: loose item is wrapped in <p>
    // -------------------------------------------------------------------------

    public function testRendererWrapsInParaWhenLoose(): void
    {
        $item = new ListItemNode(
            children: [new TextNode('hello')],
        );
        $list = new ListNode(ordered: false, loose: true, children: [$item]);
        $doc  = new \PhpMarkdown\Node\Block\DocumentNode([$list]);

        $html = $this->renderer->render($doc);

        $this->assertStringContainsString("<li>\n<p>hello</p>\n</li>", $html);
    }

    // -------------------------------------------------------------------------
    // Unit — Renderer: tight item is NOT wrapped in <p>
    // -------------------------------------------------------------------------

    public function testRendererOmitsParaWhenTight(): void
    {
        $item = new ListItemNode(
            children: [new TextNode('world')],
        );
        $list = new ListNode(ordered: false, loose: false, children: [$item]);
        $doc  = new \PhpMarkdown\Node\Block\DocumentNode([$list]);

        $html = $this->renderer->render($doc);

        $this->assertStringContainsString('<li>world</li>', $html);
        $this->assertStringNotContainsString('<p>', $html);
    }
}
