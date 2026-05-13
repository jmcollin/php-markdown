<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit;

use PhpMarkdown\Node\Block\BlockquoteNode;
use PhpMarkdown\Node\Block\DocumentNode;
use PhpMarkdown\Node\Block\FencedCodeNode;
use PhpMarkdown\Node\Block\HeadingNode;
use PhpMarkdown\Node\Block\HorizontalRuleNode;
use PhpMarkdown\Node\Block\ListItemNode;
use PhpMarkdown\Node\Block\ListNode;
use PhpMarkdown\Node\Block\ParagraphNode;
use PhpMarkdown\Node\Block\TableCellNode;
use PhpMarkdown\Node\Block\TableNode;
use PhpMarkdown\Node\Block\TableRowNode;
use PhpMarkdown\Node\Inline\CodeNode;
use PhpMarkdown\Node\Inline\EmphasisNode;
use PhpMarkdown\Node\Inline\ImageNode;
use PhpMarkdown\Node\Inline\LinkNode;
use PhpMarkdown\Node\Inline\StrikethroughNode;
use PhpMarkdown\Node\Inline\StrongNode;
use PhpMarkdown\Node\Inline\TextNode;
use PhpMarkdown\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase
{
    private HtmlRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new HtmlRenderer();
    }

    private function render(array $children): string
    {
        return $this->renderer->render(new DocumentNode($children));
    }

    public function testEmptyDocumentRendersEmptyString(): void
    {
        $this->assertSame('', $this->render([]));
    }

    #[DataProvider('headingProvider')]
    public function testHeadingRendered(int $level, string $expected): void
    {
        $out = $this->render([new HeadingNode($level, [new TextNode('T')])]);
        $this->assertSame($expected, $out);
    }

    /** @return array<string, array{int, string}> */
    public static function headingProvider(): array
    {
        return array_combine(
            array_map(fn($l) => "h{$l}", range(1, 6)),
            array_map(fn($l) => [$l, "<h{$l}>T</h{$l}>"], range(1, 6)),
        );
    }

    public function testXssInTextNodeEscaped(): void
    {
        $out = $this->render([new ParagraphNode([new TextNode('<script>alert(1)</script>')])]);

        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('&lt;script&gt;', $out);
    }

    public function testAmpersandEscaped(): void
    {
        $out = $this->render([new ParagraphNode([new TextNode('a & b')])]);
        $this->assertSame('<p>a &amp; b</p>', $out);
    }

    public function testQuoteEscapedInAttribute(): void
    {
        $out = $this->render([new ParagraphNode([new ImageNode('x.png', '"quoted"')])]);
        $this->assertStringContainsString('&quot;quoted&quot;', $out);
    }

    public function testLinkRendered(): void
    {
        $out = $this->render([new ParagraphNode([
            new LinkNode('https://example.com', [new TextNode('click')]),
        ])]);
        $this->assertSame('<p><a href="https://example.com">click</a></p>', $out);
    }

    public function testLinkHrefEscaped(): void
    {
        $out = $this->render([new ParagraphNode([
            new LinkNode('https://ex.com?q=<b>', [new TextNode('x')]),
        ])]);
        $this->assertStringContainsString('&lt;b&gt;', $out);
        $this->assertStringNotContainsString('<b>', $out);
    }

    public function testImageRendered(): void
    {
        $out = $this->render([new ParagraphNode([new ImageNode('img.png', 'desc')])]);
        $this->assertSame('<p><img src="img.png" alt="desc"></p>', $out);
    }

    public function testImageWithTitleRendered(): void
    {
        $out = $this->render([new ParagraphNode([new ImageNode('img.png', 'alt text', 'My tooltip')])]);
        $this->assertSame('<p><img src="img.png" alt="alt text" title="My tooltip"></p>', $out);
    }

    public function testImageTitleEscaped(): void
    {
        $out = $this->render([new ParagraphNode([new ImageNode('img.png', 'alt', '<script>')])]);
        $this->assertStringContainsString('title="&lt;script&gt;"', $out);
    }

    public function testFencedCodeWithLanguage(): void
    {
        $out = $this->render([new FencedCodeNode("echo 'x';", 'php')]);
        $this->assertSame('<pre><code class="language-php">echo &#039;x&#039;;</code></pre>', $out);
    }

    public function testFencedCodeContentEscaped(): void
    {
        $out = $this->render([new FencedCodeNode('<script>', 'html')]);
        $this->assertStringContainsString('&lt;script&gt;', $out);
    }

    public function testUnorderedList(): void
    {
        $out = $this->render([new ListNode(false, [
            new ListItemNode([new TextNode('a')]),
            new ListItemNode([new TextNode('b')]),
        ])]);
        $this->assertSame('<ul><li>a</li><li>b</li></ul>', $out);
    }

    public function testOrderedList(): void
    {
        $out = $this->render([new ListNode(true, [
            new ListItemNode([new TextNode('a')]),
        ])]);
        $this->assertSame('<ol><li>a</li></ol>', $out);
    }

    public function testBlockquoteRendered(): void
    {
        $out = $this->render([new BlockquoteNode([
            new ParagraphNode([new TextNode('quote')]),
        ])]);
        $this->assertSame('<blockquote><p>quote</p></blockquote>', $out);
    }

    public function testHorizontalRuleRendered(): void
    {
        $out = $this->render([new HorizontalRuleNode()]);
        $this->assertSame('<hr>', $out);
    }

    public function testInlineCodeEscaped(): void
    {
        $out = $this->render([new ParagraphNode([new CodeNode('<b>')])]);
        $this->assertSame('<p><code>&lt;b&gt;</code></p>', $out);
    }

    public function testStrongAndEmRendered(): void
    {
        $out = $this->render([new ParagraphNode([
            new StrongNode([new TextNode('b')]),
            new EmphasisNode([new TextNode('i')]),
        ])]);
        $this->assertSame('<p><strong>b</strong><em>i</em></p>', $out);
    }

    public function testTableRendered(): void
    {
        $out = $this->render([new TableNode([
            new TableRowNode([
                new TableCellNode([new TextNode('Name')], 'left'),
                new TableCellNode([new TextNode('Age')], 'right'),
            ], isHeader: true),
            new TableRowNode([
                new TableCellNode([new TextNode('Alice')], 'left'),
                new TableCellNode([new TextNode('30')], 'right'),
            ], isHeader: false),
        ])]);

        $this->assertStringContainsString('<thead>', $out);
        $this->assertStringContainsString('<tbody>', $out);
        $this->assertStringContainsString('<th align="left">Name</th>', $out);
        $this->assertStringContainsString('<th align="right">Age</th>', $out);
        $this->assertStringContainsString('<td align="left">Alice</td>', $out);
        $this->assertStringContainsString('<td align="right">30</td>', $out);
    }

    public function testTableAlignmentAttribute(): void
    {
        $out = $this->render([new TableNode([
            new TableRowNode([
                new TableCellNode([new TextNode('X')], 'center'),
                new TableCellNode([new TextNode('Y')]),
            ], isHeader: true),
        ])]);

        $this->assertStringContainsString('align="center"', $out);
        $this->assertStringNotContainsString('align=""', $out);
    }

    public function testTableCellContentEscaped(): void
    {
        $out = $this->render([new TableNode([
            new TableRowNode([
                new TableCellNode([new TextNode('<script>')]),
            ], isHeader: true),
        ])]);

        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('&lt;script&gt;', $out);
    }

    public function testMermaidBlockRenderedAsDiv(): void
    {
        $out = $this->render([new FencedCodeNode('graph TD;', 'mermaid')]);
        $this->assertSame('<div class="mermaid">graph TD;</div>', $out);
    }

    public function testMermaidXssPayloadEscaped(): void
    {
        $out = $this->render([new FencedCodeNode('<script>alert(1)</script>', 'mermaid')]);
        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $out);
        $this->assertStringContainsString('<div class="mermaid">', $out);
    }

    public function testMermaidEmptyContent(): void
    {
        $out = $this->render([new FencedCodeNode('', 'mermaid')]);
        $this->assertSame('<div class="mermaid"></div>', $out);
    }

    public function testMermaidMultilineContentPreserved(): void
    {
        $out = $this->render([new FencedCodeNode("graph TD\n  A --> B\n  B --> C", 'mermaid')]);
        $this->assertSame("<div class=\"mermaid\">graph TD\n  A --&gt; B\n  B --&gt; C</div>", $out);
    }

    public function testMermaidSpecialCharsEscaped(): void
    {
        $out = $this->render([new FencedCodeNode('A["label & <tag> \'q\' "x""]', 'mermaid')]);
        $this->assertStringContainsString('&amp;', $out);
        $this->assertStringContainsString('&lt;tag&gt;', $out);
        $this->assertStringContainsString('&#039;q&#039;', $out);
        $this->assertStringContainsString('&quot;x&quot;', $out);
        $this->assertStringNotContainsString('<tag>', $out);
    }

    public function testMermaidTrailingNewlinePreserved(): void
    {
        $out = $this->render([new FencedCodeNode("graph TD;\n", 'mermaid')]);
        $this->assertSame("<div class=\"mermaid\">graph TD;\n</div>", $out);
    }

    public function testMermaidCaseUppercaseFallsBackToPreCode(): void
    {
        $out = $this->render([new FencedCodeNode('x', 'MERMAID')]);
        $this->assertStringContainsString('<pre><code', $out);
        $this->assertStringNotContainsString('<div class="mermaid">', $out);
    }

    public function testMermaidCaseTitleCaseFallsBackToPreCode(): void
    {
        $out = $this->render([new FencedCodeNode('x', 'Mermaid')]);
        $this->assertStringContainsString('<pre><code', $out);
        $this->assertStringNotContainsString('<div class="mermaid">', $out);
    }

    public function testMixedDocumentMermaidAndCodeBlock(): void
    {
        $out = $this->render([
            new FencedCodeNode('graph TD;', 'mermaid'),
            new FencedCodeNode("echo 'x';", 'php'),
        ]);
        $this->assertStringContainsString('<div class="mermaid">graph TD;</div>', $out);
        $this->assertStringContainsString('<pre><code class="language-php">', $out);
    }

    // ── Strikethrough rendering ──────────────────────────────────────────────

    public function testStrikethroughRendered(): void
    {
        $out = $this->render([new ParagraphNode([new StrikethroughNode([new TextNode('foo')])])]);
        $this->assertSame('<p><del>foo</del></p>', $out);
    }

    public function testStrikethroughXssEscaped(): void
    {
        $out = $this->render([new ParagraphNode([new StrikethroughNode([new TextNode('<b>')])])]);
        $this->assertSame('<p><del>&lt;b&gt;</del></p>', $out);
    }

    // ── Nested list rendering ────────────────────────────────────────────────

    public function testNestedUnorderedList(): void
    {
        $out = $this->render([
            new ListNode(false, [
                new ListItemNode([
                    new TextNode('a'),
                    new ListNode(false, [
                        new ListItemNode([new TextNode('b')]),
                    ]),
                ]),
            ]),
        ]);
        $this->assertSame('<ul><li>a<ul><li>b</li></ul></li></ul>', $out);
    }

    public function testMixedOrderedUnorderedNesting(): void
    {
        $out = $this->render([
            new ListNode(true, [
                new ListItemNode([
                    new TextNode('first'),
                    new ListNode(false, [
                        new ListItemNode([new TextNode('nested')]),
                    ]),
                ]),
            ]),
        ]);
        $this->assertSame('<ol><li>first<ul><li>nested</li></ul></li></ol>', $out);
    }

    // ── Task list (checkbox) rendering ───────────────────────────────────────

    public function testCheckedListItemRendersCheckbox(): void
    {
        $out = $this->render([new ListNode(false, [
            new ListItemNode([new TextNode('Done')], checked: true),
        ])]);
        $this->assertSame('<ul><li><input type="checkbox" disabled checked> Done</li></ul>', $out);
    }

    public function testUncheckedListItemRendersCheckbox(): void
    {
        $out = $this->render([new ListNode(false, [
            new ListItemNode([new TextNode('Todo')], checked: false),
        ])]);
        $this->assertSame('<ul><li><input type="checkbox" disabled> Todo</li></ul>', $out);
    }

    public function testPlainListItemNoCheckbox(): void
    {
        $out = $this->render([new ListNode(false, [
            new ListItemNode([new TextNode('plain')], checked: null),
        ])]);
        $this->assertSame('<ul><li>plain</li></ul>', $out);
    }
}
