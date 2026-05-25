<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit;

use PhpMarkdown\Node\Block\DocumentNode;
use PhpMarkdown\Node\Block\ParagraphNode;
use PhpMarkdown\Node\Inline\HtmlEntityNode;
use PhpMarkdown\Node\Inline\TextNode;
use PhpMarkdown\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

final class RendererEntityTest extends TestCase
{
    private HtmlRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new HtmlRenderer();
    }

    /** @param \PhpMarkdown\Node\NodeInterface[] $children */
    private function render(array $children): string
    {
        return $this->renderer->render(new DocumentNode($children));
    }

    public function testHtmlEntityNodeRenderedVerbatim(): void
    {
        $out = $this->render([new ParagraphNode([new HtmlEntityNode('&amp;')])]);

        // Must be &amp; exactly — no double-escaping to &amp;amp;
        $this->assertSame('<p>&amp;</p>', $out);
    }

    public function testHtmlEntityNodeLtRenderedVerbatim(): void
    {
        $out = $this->render([new ParagraphNode([new HtmlEntityNode('&lt;')])]);

        $this->assertSame('<p>&lt;</p>', $out);
    }

    public function testHtmlEntityNodeHexRenderedVerbatim(): void
    {
        $out = $this->render([new ParagraphNode([new HtmlEntityNode('&#x00A0;')])]);

        $this->assertSame('<p>&#x00A0;</p>', $out);
    }

    public function testTextNodeBareAmpersandIsEscaped(): void
    {
        // Regression guard: TextNode('a & b') must still produce a &amp; b.
        $out = $this->render([new ParagraphNode([new TextNode('a & b')])]);

        $this->assertSame('<p>a &amp; b</p>', $out);
    }
}
