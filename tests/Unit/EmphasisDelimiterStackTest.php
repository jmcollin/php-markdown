<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit;

use PhpMarkdown\Node\Block\DocumentNode;
use PhpMarkdown\Node\Block\ParagraphNode;
use PhpMarkdown\Parser\InlineParser;
use PhpMarkdown\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the CommonMark §6.2 delimiter stack algorithm.
 *
 * All assertions go through InlineParser::parse() + HtmlRenderer to verify
 * the full parsing and rendering pipeline.
 */
final class EmphasisDelimiterStackTest extends TestCase
{
    private InlineParser $parser;
    private HtmlRenderer $renderer;

    protected function setUp(): void
    {
        $this->parser   = new InlineParser();
        $this->renderer = new HtmlRenderer();
    }

    /**
     * Parse inline text and return the rendered paragraph inner HTML.
     */
    private function parse(string $input): string
    {
        $nodes    = $this->parser->parse($input);
        $para     = new ParagraphNode($nodes);
        $document = new DocumentNode([$para]);
        $html     = $this->renderer->render($document);
        return preg_replace('/^<p>(.*)<\/p>$/s', '$1', $html) ?? $html;
    }

    // ── Basic emphasis and strong ─────────────────────────────────────────────

    public function test_single_asterisk_emphasis(): void
    {
        $this->assertSame('<em>foo</em>', $this->parse('*foo*'));
    }

    public function test_single_underscore_emphasis(): void
    {
        $this->assertSame('<em>foo</em>', $this->parse('_foo_'));
    }

    public function test_double_asterisk_strong(): void
    {
        $this->assertSame('<strong>foo</strong>', $this->parse('**foo**'));
    }

    public function test_double_underscore_strong(): void
    {
        $this->assertSame('<strong>foo</strong>', $this->parse('__foo__'));
    }

    // ── Triple delimiter: em wrapping strong ─────────────────────────────────

    public function test_triple_asterisk_yields_em_strong(): void
    {
        // ***foo*** → <em><strong>foo</strong></em>
        // Outer * opens em, inner ** opens strong.
        $this->assertSame('<em><strong>foo</strong></em>', $this->parse('***foo***'));
    }

    // ── Intraword rules ───────────────────────────────────────────────────────

    public function test_intraword_underscore_is_literal(): void
    {
        // '_' is not left-flanking when preceded by word char and followed by word char.
        $this->assertSame('foo_bar_baz', $this->parse('foo_bar_baz'));
    }

    public function test_intraword_asterisk_is_emphasis(): void
    {
        // '*' is always left-flanking if next char is not whitespace.
        $this->assertSame('foo<em>bar</em>baz', $this->parse('foo*bar*baz'));
    }

    // ── Cross-delimiter interaction ───────────────────────────────────────────

    /**
     * CommonMark Appendix A resolution: the inner '_' opener at pos 5 (preceded by space → not
     * right-flanking) gets removed when the outer '*…*' span is resolved. The '_' closer at
     * pos 14 has no matching opener and becomes literal.
     * Correct output per spec: <em>foo _bar</em> baz_
     */
    public function test_asterisk_spans_over_inner_underscore(): void
    {
        $this->assertSame('<em>foo _bar</em> baz_', $this->parse('*foo _bar* baz_'));
    }

    // ── Space-adjacent delimiters ─────────────────────────────────────────────

    public function test_space_adjacent_asterisk_is_literal(): void
    {
        // '* foo *' — left '*' is right-flanking (next is space) → canOpen=false; literal.
        $this->assertSame('* foo *', $this->parse('* foo *'));
    }

    // ── Shorter match wins ────────────────────────────────────────────────────

    public function test_shorter_match_wins(): void
    {
        // '***foo*' — closer '*' (count=1) matches opener with matchLen=1.
        // Remaining '**' opener has no closer → literal '**'.
        $this->assertSame('**<em>foo</em>', $this->parse('***foo*'));
    }

    // ── Unmatched / literal delimiters ───────────────────────────────────────

    public function test_double_delimiter_alone_is_literal(): void
    {
        $this->assertSame('**', $this->parse('**'));
    }

    public function test_empty_single_delimiter_is_literal(): void
    {
        $this->assertSame('*', $this->parse('*'));
    }

    public function test_four_asterisks_is_literal(): void
    {
        $this->assertSame('****', $this->parse('****'));
    }

    // ── Nesting ───────────────────────────────────────────────────────────────

    public function test_nested_emphasis_in_strong(): void
    {
        $this->assertSame('<strong>foo <em>bar</em> baz</strong>', $this->parse('**foo *bar* baz**'));
    }

    // ── Emphasis wrapping a code span ─────────────────────────────────────────

    public function test_emphasis_wraps_code_span(): void
    {
        $this->assertSame('<em>foo <code>code</code> bar</em>', $this->parse('*foo `code` bar*'));
    }

    // ── Intraword underscore — no close ──────────────────────────────────────

    public function test_intraword_underscore_no_close(): void
    {
        // '_foo_bar': opener '_' is left-flanking, but closer '_' at pos 4 is also right-flanking
        // (preceded by 'o', followed by 'b' — word char on both sides) so its canClose=false for '_'.
        $this->assertSame('_foo_bar', $this->parse('_foo_bar'));
    }

    // ── Unicode boundary ─────────────────────────────────────────────────────

    public function test_unicode_boundary_emphasis(): void
    {
        // '*α*' — boundaries are SOL/EOL → left and right flanking → emphasis.
        $this->assertSame('<em>α</em>', $this->parse('*α*'));
    }

    // ── Cross-newline strong ──────────────────────────────────────────────────

    public function test_strong_across_newline(): void
    {
        $this->assertSame('<strong>foo' . "\n" . 'bar</strong>', $this->parse("**foo\nbar**"));
    }
}
