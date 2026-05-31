<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit;

use PhpMarkdown\Lexer\Lexer;
use PhpMarkdown\Node\Block\ColumnsNode;
use PhpMarkdown\Node\Block\DocumentNode;
use PhpMarkdown\Node\Block\ParagraphNode;
use PhpMarkdown\Node\Inline\TextNode;
use PhpMarkdown\Parser\Parser;
use PhpMarkdown\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

final class ColumnsRendererTest extends TestCase
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

    private function renderMarkdown(string $markdown): string
    {
        return $this->renderer->render(
            $this->parser->parse($this->lexer->tokenize($markdown))
        );
    }

    /** @param \PhpMarkdown\Node\NodeInterface[] $children */
    private function renderNode(array $children): string
    {
        return $this->renderer->render(new DocumentNode($children));
    }

    // -------------------------------------------------------------------------
    // Basic structure
    // -------------------------------------------------------------------------

    public function testBasicTwoColumnHtmlStructure(): void
    {
        $out = $this->renderMarkdown(":::columns\nGauche\n|||\nDroite\n:::");

        $this->assertSame(
            '<div class="grid grid-cols-2 gap-4"><div class="min-w-0"><p>Gauche</p>' . "\n" . '</div><div class="min-w-0"><p>Droite</p>' . "\n" . '</div></div>',
            $out,
        );
    }

    public function testRenderColumnsNodeDoesNotThrowRuntimeException(): void
    {
        $node = new ColumnsNode(
            leftChildren: [new ParagraphNode([new TextNode('Left')])],
            rightChildren: [new ParagraphNode([new TextNode('Right')])],
        );

        // Must not throw RuntimeException (match-arm is present)
        $out = $this->renderNode([$node]);
        $this->assertStringContainsString('class="grid grid-cols-2 gap-4"', $out);
    }

    // -------------------------------------------------------------------------
    // Acceptance criteria scenarios
    // -------------------------------------------------------------------------

    public function testInlineMarkdownRenderedInColumns(): void
    {
        $out = $this->renderMarkdown(":::columns\n**gras** et *italic*\n|||\n[lien](https://example.com)\n:::");

        $this->assertStringContainsString('<strong>gras</strong>', $out);
        $this->assertStringContainsString('<em>italic</em>', $out);
        $this->assertStringContainsString('<a href="https://example.com">lien</a>', $out);
    }

    public function testListInsideColumn(): void
    {
        $out = $this->renderMarkdown(":::columns\n- item1\n- item2\n|||\nRight\n:::");

        $this->assertStringContainsString('<ul>', $out);
        $this->assertStringContainsString('<li>item1</li>', $out);
        $this->assertStringContainsString('<li>item2</li>', $out);
    }

    // -------------------------------------------------------------------------
    // Empty / whitespace columns
    // -------------------------------------------------------------------------

    public function testEmptyLeftColumn(): void
    {
        $out = $this->renderMarkdown(":::columns\n|||\nDroite\n:::");

        $this->assertStringContainsString('<div class="min-w-0"></div>', $out);
        $this->assertStringContainsString('<p>Droite</p>', $out);
    }

    public function testEmptyRightColumn(): void
    {
        $out = $this->renderMarkdown(":::columns\nGauche\n|||\n:::");

        $this->assertStringContainsString('<p>Gauche</p>', $out);
        // Right column div must be present but empty
        $this->assertSame(1, substr_count($out, '<div class="min-w-0"></div>'));
    }

    public function testWhitespaceOnlyColumnsAreEmpty(): void
    {
        $out = $this->renderMarkdown(":::columns\n   \n|||\n   \n:::");

        $this->assertSame(
            '<div class="grid grid-cols-2 gap-4"><div class="min-w-0"></div><div class="min-w-0"></div></div>',
            $out,
        );
    }

    public function testMissingSeparatorSingleColumn(): void
    {
        $out = $this->renderMarkdown(":::columns\nTout le contenu sans séparateur\n:::");

        $this->assertStringContainsString('class="grid grid-cols-2 gap-4"', $out);
        $this->assertStringContainsString('Tout le contenu', $out);
        // Right column is empty
        $this->assertStringContainsString('<div class="min-w-0"></div>', $out);
    }

    // -------------------------------------------------------------------------
    // Fenced code and GFM table inside columns
    // -------------------------------------------------------------------------

    public function testFencedCodeInsideColumn(): void
    {
        $out = $this->renderMarkdown(":::columns\n```php\necho 'hi';\n```\n|||\nRight\n:::");

        $this->assertStringContainsString('<pre><code', $out);
        $this->assertStringContainsString("echo &#039;hi&#039;;", $out);
    }

    public function testGfmTableInsideColumn(): void
    {
        $left = "| Name | Age |\n|------|-----|\n| Alice | 30 |";
        $out  = $this->renderMarkdown(":::columns\n{$left}\n|||\nRight\n:::");

        $this->assertStringContainsString('<table>', $out);
        $this->assertStringContainsString('<thead>', $out);
        $this->assertStringContainsString('<tbody>', $out);
    }

    // -------------------------------------------------------------------------
    // XSS vectors
    // -------------------------------------------------------------------------

    public function testXssScriptTagIsEncoded(): void
    {
        $out = $this->renderMarkdown(":::columns\n<script>alert(1)</script>\n|||\nok\n:::");

        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('&lt;script&gt;', $out);
    }

    public function testXssDoubleQuoteIsEncoded(): void
    {
        $out = $this->renderMarkdown(":::columns\n\"><img src=x onerror=alert(1)>\n|||\nok\n:::");

        $this->assertStringNotContainsString('<img', $out);
        $this->assertStringContainsString('&quot;', $out);
    }

    public function testXssJavascriptUrlIsNotRenderedAsHref(): void
    {
        $out = $this->renderMarkdown(":::columns\n[click](javascript:alert(1))\n|||\nok\n:::");

        // isSafeUrl() strips javascript: links — they must not appear as <a href="javascript:...">
        $this->assertStringNotContainsString('href="javascript:', $out);
    }

    public function testXssNullByteIsStrippedFromOutput(): void
    {
        $out = $this->renderMarkdown(":::columns\nfoo\x00bar\n|||\nok\n:::");

        // esc() strips \x00 before htmlspecialchars — null byte must not appear in output.
        $this->assertStringNotContainsString("\x00", $out);
        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('foobar', $out);
    }

    public function testXssHtmlEntitySmuggling(): void
    {
        $out = $this->renderMarkdown(":::columns\n&lt;script&gt;alert(1)&lt;/script&gt;\n|||\nok\n:::");

        // &lt; and &gt; are valid HTML entities and pass through verbatim (InlineParser entity branch).
        // The output contains &lt;script&gt; — safe in HTML because the browser renders it as text,
        // not as a live <script> tag.
        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('&lt;script&gt;', $out);
    }

    public function testXssClassAttributesAreHardCodedLiterals(): void
    {
        $out = $this->renderMarkdown(":::columns\nLeft\n|||\nRight\n:::");

        // class values are Tailwind utility literals — no user input reaches any class attribute
        $this->assertStringContainsString('class="grid grid-cols-2 gap-4"', $out);
        $this->assertStringContainsString('class="min-w-0"', $out);
        $this->assertSame(1, substr_count($out, 'class="grid grid-cols-2 gap-4"'));
        $this->assertSame(2, substr_count($out, 'class="min-w-0"'));
    }

    // -------------------------------------------------------------------------
    // Tailwind layout structure assertions
    // -------------------------------------------------------------------------

    public function testColumnsNodeRendersCorrectHtmlStructure(): void
    {
        $out = $this->renderMarkdown(":::columns\nA\n|||\nB\n:::");

        $this->assertMatchesRegularExpression(
            '/<div class="grid grid-cols-2 gap-4"><div class="min-w-0">.*<\/div><div class="min-w-0">.*<\/div><\/div>/s',
            $out,
        );
    }

    public function testNullByteStrippedGlobally(): void
    {
        // Verify esc() strips \x00 even in other inline contexts (not just columns)
        $out = $this->renderMarkdown("foo\x00bar");

        $this->assertStringNotContainsString("\x00", $out);
        $this->assertStringContainsString('foobar', $out);
    }
}
