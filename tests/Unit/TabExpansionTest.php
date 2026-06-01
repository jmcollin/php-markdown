<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit;

use PhpMarkdown\Lexer\Lexer;
use PhpMarkdown\Parser\Parser;
use PhpMarkdown\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

final class TabExpansionTest extends TestCase
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

    /**
     * CommonMark spec §2.1 example 1: a single leading tab is equivalent to 4 spaces.
     * Internal tabs in the content must be preserved verbatim.
     */
    public function testSpecExample1_SingleLeadingTabProducesCodeBlock(): void
    {
        $html = $this->render("\tfoo\tbaz\t\tbim");

        $this->assertSame("<pre><code>foo\tbaz\t\tbim\n</code></pre>\n", $html);
    }

    /**
     * CommonMark spec §2.1 example 2: two spaces + tab = 4 columns, still an indented code block.
     */
    public function testSpecExample2_TwoSpacesPlusTabProducesCodeBlock(): void
    {
        $html = $this->render("  \tfoo\tbaz\t\tbim");

        $this->assertSame("<pre><code>foo\tbaz\t\tbim\n</code></pre>\n", $html);
    }

    /**
     * CommonMark spec §2.1 example 4: tab continuation in a loose list item.
     * Deferred — requires Parser-level list continuation changes.
     */
    public function testSpecExample4_TabContinuationInLooseListItem(): void
    {
        // TODO: story 54+ — list continuation tab handling requires Parser changes
        $this->markTestSkipped('TODO: story 54+ — list continuation tab handling requires Parser changes');
    }

    /**
     * CommonMark spec §2.1 example 5: double tab in list item produces indented code block.
     * Deferred — requires Parser-level list continuation changes.
     */
    public function testSpecExample5_DoubleTabInListItemProducesCodeBlock(): void
    {
        // TODO: story 54+ — list continuation tab handling requires Parser changes
        $this->markTestSkipped('TODO: story 54+ — list continuation tab handling requires Parser changes');
    }

    /**
     * CommonMark spec §2.1 example 6: a tab after a blockquote marker `>` can contribute
     * surplus spaces that push the inner content into an indented code block.
     * `>\t\tfoo` → the first tab after `>` overshoots the mandatory separator by 2 spaces;
     * those 2 surplus spaces plus the second tab (2 more cols to reach col 4) form 4-col indent.
     */
    public function testSpecExample6_TabAfterBlockquoteMarkerProducesCodeBlock(): void
    {
        $html = $this->render(">\t\tfoo");

        $this->assertSame("<blockquote>\n<pre><code>  foo\n</code></pre>\n</blockquote>\n", $html);
    }

    /**
     * CommonMark spec §2.1 example 10: a tab after a heading marker `#` is a valid separator.
     * This already worked before the story; this is a regression guard.
     */
    public function testSpecExample10_TabAfterHeadingMarkerIsValidSeparator(): void
    {
        $html = $this->render("#\tFoo");

        $this->assertSame("<h1>Foo</h1>\n", $html);
    }

    /**
     * CommonMark spec §2.1 example 11: asterisks separated by tabs form a thematic break.
     */
    public function testSpecExample11_TabSeparatedAsterisksFormThematicBreak(): void
    {
        $html = $this->render("*\t*\t*\t");

        $this->assertSame("<hr />\n", $html);
    }

    /**
     * Edge case A: three spaces + tab = exactly 4 columns → indented code block.
     * Content after stripping 4 cols is "foo" (no leading spaces).
     */
    public function testEdgeCaseA_ThreeSpacesPlusTabEqualsExactlyFourColumns(): void
    {
        $html = $this->render("   \tfoo");

        $this->assertSame("<pre><code>foo\n</code></pre>\n", $html);
    }

    /**
     * Edge case B: four spaces + tab = 8 columns → indented code block with extra indent.
     * Stripping 4 cols leaves a literal tab as the first content character.
     */
    public function testEdgeCaseB_FourSpacesPlusTabPreservesExtraIndent(): void
    {
        $html = $this->render("    \tfoo");

        $this->assertSame("<pre><code>\tfoo\n</code></pre>\n", $html);
    }

    /**
     * Edge case C: a single tab after `>` gives only 3 surplus spaces (cols 1–3),
     * which is NOT enough for an indented code block (needs 4 cols).
     * The blockquote content must therefore be a plain paragraph.
     */
    public function testEdgeCaseC_SingleTabAfterBlockquoteIsNotCodeBlock(): void
    {
        $html = $this->render(">\tbar");

        $this->assertStringContainsString('<blockquote>', $html);
        $this->assertStringContainsString('<p>', $html);
        $this->assertStringContainsString('bar', $html);
        $this->assertStringNotContainsString('<pre>', $html);
    }

    /**
     * Regression D: the classic 4-space indented code block must still work.
     */
    public function testRegressionD_PlainFourSpaceIndentStillProducesCodeBlock(): void
    {
        $html = $this->render("    foo");

        $this->assertSame("<pre><code>foo\n</code></pre>\n", $html);
    }

    /**
     * Regression E: a plain paragraph must be completely unaffected by tab expansion logic.
     */
    public function testRegressionE_PlainParagraphUnaffectedByTabExpansionLogic(): void
    {
        $html = $this->render("hello world");

        $this->assertSame("<p>hello world</p>\n", $html);
    }

    /**
     * Regression G: trailing double spaces inside a blockquote must produce a hard line break.
     * rtrim on blockquote inner content would strip the spaces and lose the hard-break signal.
     */
    public function testRegressionG_HardLineBreakInsideBlockquoteIsPreserved(): void
    {
        $html = $this->render("> foo  \n> bar");

        $this->assertStringContainsString('<br', $html);
        $this->assertStringContainsString('foo', $html);
        $this->assertStringContainsString('bar', $html);
    }

    /**
     * Edge case F: a tab inside a fenced code block must be preserved verbatim.
     */
    public function testEdgeCaseF_TabInsideFencedCodeBlockPreservedVerbatim(): void
    {
        $markdown = "```\nfoo\tbar\n```";
        $html     = $this->render($markdown);

        $this->assertStringContainsString("foo\tbar", $html);
        $this->assertStringNotContainsString('foo    bar', $html);
        $this->assertStringNotContainsString('foo   bar', $html);
    }
}
