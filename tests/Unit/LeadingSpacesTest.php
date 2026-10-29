<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit;

use PhpMarkdown\Lexer\Lexer;
use PhpMarkdown\Parser\Parser;
use PhpMarkdown\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

final class LeadingSpacesTest extends TestCase
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

    private function render(string $input): string
    {
        return $this->renderer->render(
            $this->parser->parse($this->lexer->tokenize($input))
        );
    }

    // ATX Headings

    public function testHeadingWithOneLeadingSpace(): void
    {
        self::assertSame("<h1>foo</h1>\n", $this->render(' # foo'));
    }

    public function testHeadingWithTwoLeadingSpaces(): void
    {
        self::assertSame("<h2>foo</h2>\n", $this->render('  ## foo'));
    }

    public function testHeadingWithThreeLeadingSpaces(): void
    {
        self::assertSame("<h3>foo</h3>\n", $this->render('   ### foo'));
    }

    public function testThreeHeadingsWithVariousLeadingSpaces(): void
    {
        $input    = " ### foo\n  ## foo\n   # foo";
        $expected = "<h3>foo</h3>\n<h2>foo</h2>\n<h1>foo</h1>\n";
        self::assertSame($expected, $this->render($input));
    }

    public function testFourSpaceHeadingBecomesIndentedCode(): void
    {
        self::assertSame("<pre><code># foo\n</code></pre>\n", $this->render('    # foo'));
    }

    public function testFourSpaceHeadingInsideParagraphStaysParagraph(): void
    {
        $input    = "foo\n    # bar";
        $expected = "<p>foo\n# bar</p>\n";
        self::assertSame($expected, $this->render($input));
    }

    // Thematic Breaks

    public function testThematicBreakWithOneLeadingSpace(): void
    {
        self::assertSame("<hr />\n", $this->render(' ***'));
    }

    public function testThematicBreakWithTwoLeadingSpaces(): void
    {
        self::assertSame("<hr />\n", $this->render('  ***'));
    }

    public function testThematicBreakWithThreeLeadingSpaces(): void
    {
        self::assertSame("<hr />\n", $this->render('   ***'));
    }

    public function testFourSpaceThematicBreakBecomesIndentedCode(): void
    {
        self::assertSame("<pre><code>***\n</code></pre>\n", $this->render('    ***'));
    }

    public function testShortDashesAreNotThematicBreaks(): void
    {
        $input    = "--\n**\n__";
        $expected = "<p>--\n**\n__</p>\n";
        self::assertSame($expected, $this->render($input));
    }

    // Blockquotes

    public function testBlockquoteWithNoSpaceAfterMarker(): void
    {
        $input    = "># Foo\n>bar\n> baz";
        $expected = "<blockquote>\n<h1>Foo</h1>\n<p>bar\nbaz</p>\n</blockquote>\n";
        self::assertSame($expected, $this->render($input));
    }

    public function testBlockquoteWithUpToThreeLeadingSpaces(): void
    {
        $input    = "   > # Foo\n   > bar\n > baz";
        $expected = "<blockquote>\n<h1>Foo</h1>\n<p>bar\nbaz</p>\n</blockquote>\n";
        self::assertSame($expected, $this->render($input));
    }

    public function testFourSpaceBlockquoteBecomesIndentedCode(): void
    {
        $input    = "    > # Foo\n    > bar\n    > baz";
        $expected = "<pre><code>&gt; # Foo\n&gt; bar\n&gt; baz\n</code></pre>\n";
        self::assertSame($expected, $this->render($input));
    }

    // Edge cases

    public function testHeadingWithThreeLeadingSpacesAndTrailingHashes(): void
    {
        self::assertSame("<h1>foo</h1>\n", $this->render('   # foo #'));
    }

    public function testHeadingWithNoContentAfterMarkers(): void
    {
        self::assertSame("<h2></h2>\n", $this->render('## '));
    }

    public function testHeadingWithLeadingSpacesAndTabAfterMarker(): void
    {
        self::assertSame("<h1>foo</h1>\n", $this->render("  #\tfoo"));
    }

    public function testLeadingSpacesDoNotTurnHashWithoutSpaceIntoHeading(): void
    {
        // CommonMark §4.2: the opening '#' sequence must be followed by a space, tab or end of line.
        self::assertSame("<p>#hashtag</p>\n", $this->render(' #hashtag'));
    }

    public function testThreeThematicBreaksWithVariousLeadingSpaces(): void
    {
        self::assertSame("<hr />\n<hr />\n<hr />\n", $this->render(" ***\n  ***\n   ***"));
    }

    public function testNestedBlockquoteWithThreeLeadingSpaces(): void
    {
        $expected = "<blockquote>\n<blockquote>\n<p>x</p>\n</blockquote>\n</blockquote>\n";
        self::assertSame($expected, $this->render('   > > x'));
    }
}
