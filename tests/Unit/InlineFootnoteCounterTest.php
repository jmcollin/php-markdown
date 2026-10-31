<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit;

use PhpMarkdown\MarkdownParser;
use PhpMarkdown\Node\Inline\FootnoteRefNode;
use PhpMarkdown\Parser\InlineParser;
use PHPUnit\Framework\TestCase;

/**
 * The footnote counter is kept between inline runs for speed: numbering must still
 * follow document order, and must restart with each document.
 */
final class InlineFootnoteCounterTest extends TestCase
{
    public function testTrackedCounterContinuesAcrossCalls(): void
    {
        $parser  = new InlineParser();
        $defs    = ['a' => ['body' => 'x'], 'b' => ['body' => 'y']];
        $counter = 0;

        $first  = $parser->parse('[^a]', [], $defs, $counter);
        $second = $parser->parse('[^b]', [], $defs, $counter);

        $this->assertInstanceOf(FootnoteRefNode::class, $first[0]);
        $this->assertInstanceOf(FootnoteRefNode::class, $second[0]);
        $this->assertSame(1, $first[0]->number);
        $this->assertSame(2, $second[0]->number);
        $this->assertSame(2, $counter);
    }

    public function testWithoutCounterNumberingResumesFromTheDefinitions(): void
    {
        // Direct callers of the three-argument form rely on this.
        $parser = new InlineParser();
        $defs   = ['a' => ['body' => 'x', 'number' => 5, 'occurrences' => 1], 'b' => ['body' => 'y']];

        $nodes = $parser->parse('[^b]', [], $defs);

        $this->assertInstanceOf(FootnoteRefNode::class, $nodes[0]);
        $this->assertSame(6, $nodes[0]->number);
    }

    public function testNumberingRestartsForEachDocument(): void
    {
        // One MarkdownParser instance is reused: a counter leaking between documents
        // would number the second document's first footnote 2.
        $parser = new MarkdownParser();
        $md     = "[^a]\n\n[^a]: x\n";

        $this->assertSame($parser->parse($md), $parser->parse($md));
        $this->assertStringContainsString('id="fn-1"', $parser->parse($md));
    }
}
