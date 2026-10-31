<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Security;

use PhpMarkdown\MarkdownParser;
use PHPUnit\Framework\TestCase;

/**
 * Inline constructs that once read or copied most of the document at each occurrence:
 * under the default 1 MiB limit these inputs took more than 20 s.
 */
final class InlineQuadraticTest extends TestCase
{
    private MarkdownParser $parser;

    protected function setUp(): void
    {
        $this->parser = new MarkdownParser();
    }

    public function testManyFootnoteDefinitionsAndParagraphsParseQuickly(): void
    {
        $defs = '';
        for ($i = 1; $i <= 6_000; $i++) {
            $defs .= "[^n$i]: x\n";
        }

        $start = microtime(true);
        $html  = $this->parser->parse($defs . "\n" . str_repeat("y\n\n", 20_000));
        $this->assertLessThan(5.0, microtime(true) - $start);

        // Unreferenced definitions render nothing: only the paragraphs remain.
        $this->assertSame(20_000, substr_count($html, '<p>y</p>'));
        $this->assertStringNotContainsString('footnotes', $html);
    }

    public function testManyFootnoteReferencesKeepTheirNumbering(): void
    {
        $n  = 6_000;
        $md = '';
        for ($i = 1; $i <= $n; $i++) {
            $md .= "[^n$i]\n\n[^n$i]: x\n\n";
        }

        $start = microtime(true);
        $html  = $this->parser->parse($md);
        $this->assertLessThan(5.0, microtime(true) - $start);

        // Numbers must keep following document order across inline runs.
        $this->assertStringContainsString('<a href="#fn-1" id="fnref-1">1</a>', $html);
        $this->assertStringContainsString("<a href=\"#fn-$n\" id=\"fnref-$n\">$n</a>", $html);
    }

    public function testNestedBracketsDoNotCopyTheirLabelAtEachClose(): void
    {
        $half  = 500_000;
        $start = microtime(true);
        // A definition makes every ']' try a shortcut reference.
        $html  = $this->parser->parse("[r]: /u\n\n" . str_repeat('[', $half) . str_repeat(']', $half));
        $this->assertLessThan(10.0, microtime(true) - $start);

        $this->assertSame($half, substr_count($html, '['));
        $this->assertSame($half, substr_count($html, ']'));
    }

    public function testBacktickRunsWithoutCloserParseQuickly(): void
    {
        // Strictly decreasing lengths: no run can close an earlier one of the same length.
        $md = '';
        for ($i = 1_000; $i > 0; $i--) {
            $md .= str_repeat('`', $i) . ' ';
        }

        $start = microtime(true);
        $html  = $this->parser->parse($md);
        $this->assertLessThan(5.0, microtime(true) - $start);

        $this->assertStringStartsWith('<p>', $html);
    }
}
