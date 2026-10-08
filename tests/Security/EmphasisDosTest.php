<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Security;

use PhpMarkdown\MarkdownParser;
use PHPUnit\Framework\TestCase;

/**
 * Pathological emphasis input must parse in roughly linear time and must not
 * build an AST deep enough to overflow the stack in the recursive renderer.
 */
final class EmphasisDosTest extends TestCase
{
    private MarkdownParser $parser;

    protected function setUp(): void
    {
        $this->parser = new MarkdownParser();
    }

    public function testManyUnmatchedOpenersParseQuickly(): void
    {
        // 150 KB of "*a " — previously ~35 s (quadratic), now well under a second.
        $start = microtime(true);
        $html  = $this->parser->parse(str_repeat('*a ', 50_000));
        $this->assertLessThan(5.0, microtime(true) - $start);
        $this->assertStringNotContainsString('<em>', $html);
    }

    public function testManyUnderscoreRunsParseQuickly(): void
    {
        $start = microtime(true);
        $this->parser->parse(str_repeat('_a', 50_000));
        $this->assertLessThan(5.0, microtime(true) - $start);
    }

    public function testDeeplyNestedEmphasisDoesNotOverflowTheStack(): void
    {
        // 30k nested <em> pairs used to crash the renderer ("Maximum call stack size").
        $n     = 30_000;
        $start = microtime(true);
        $html  = $this->parser->parse(str_repeat('*a ', $n) . str_repeat(' a*', $n));
        $this->assertLessThan(10.0, microtime(true) - $start);

        // Nesting is capped at 64 levels; deeper pairs are kept as literal text.
        $this->assertSame(64, substr_count($html, '<em>'));
        $this->assertSame(64, substr_count($html, '</em>'));
        $this->assertSame(2 * ($n - 64), substr_count($html, '*'));
    }

    public function testNestingBelowTheCapIsUnchanged(): void
    {
        $this->assertSame(
            "<p><em>a <strong>b <em>c</em> d</strong> e</em></p>\n",
            $this->parser->parse('*a **b *c* d** e*'),
        );
    }
}
