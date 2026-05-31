<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit\Renderer;

use PhpMarkdown\MarkdownParser;
use PhpMarkdown\Node\Block\DocumentNode;
use PhpMarkdown\Node\Block\ParagraphNode;
use PhpMarkdown\Node\Inline\SoftBreakNode;
use PhpMarkdown\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

final class SoftLineBreakTest extends TestCase
{
    private MarkdownParser $parser;

    protected function setUp(): void
    {
        $this->parser = new MarkdownParser();
    }

    private function parseAndRender(string $markdown): string
    {
        return $this->parser->parse($markdown);
    }

    public function testSingleNewlineInParagraphPreservedAsSoftBreak(): void
    {
        $this->assertSame("<p>foo\nbaz</p>\n", $this->parseAndRender("foo\nbaz"));
    }

    public function testTrailingSpacesBeforeNewlineStrippedNotHardBreak(): void
    {
        $this->assertSame("<p>foo<br />\nbaz</p>\n", $this->parseAndRender("foo   \nbaz"));
    }

    public function testSingleTrailingSpaceBeforeNewlineIsSoftBreak(): void
    {
        $this->assertSame("<p>foo\nbaz</p>\n", $this->parseAndRender("foo \nbaz"));
    }

    public function testTwoLineParagraphPreservesInternalNewline(): void
    {
        $this->assertSame("<p>aaa\nbbb</p>\n<p>ccc\nddd</p>\n", $this->parseAndRender("aaa\nbbb\n\nccc\nddd"));
    }

    public function testLeadingSpacesOnFirstLineStripped(): void
    {
        $this->assertSame("<p>aaa\nbbb</p>\n", $this->parseAndRender("  aaa\nbbb"));
    }

    public function testLeadingSpacesOnContinuationLineStripped(): void
    {
        $this->assertSame("<p>aaa\nbbb</p>\n", $this->parseAndRender("  aaa\n bbb"));
    }

    public function testEmphasisSpanningSoftLineBreak(): void
    {
        $this->assertSame("<p><em>foo\nbar</em></p>\n", $this->parseAndRender("*foo\nbar*"));
    }

    public function testStrongEmphasisSpanningSoftLineBreak(): void
    {
        $this->assertSame("<p><strong>foo\nbar</strong></p>\n", $this->parseAndRender("**foo\nbar**"));
    }

    public function testTabBeforeContinuationTextStripped(): void
    {
        $this->assertSame("<p>foo\nbaz</p>\n", $this->parseAndRender("foo\n\tbaz"));
    }

    public function testSoftBreakNodeRendersAsNewlineNotSpace(): void
    {
        $renderer = new HtmlRenderer();
        $document = new DocumentNode([
            new ParagraphNode([new SoftBreakNode()]),
        ]);
        $this->assertSame("<p>\n</p>\n", $renderer->render($document));
    }

    public function testHardBreakWithTwoTrailingSpacesUnaffectedBySoftBreak(): void
    {
        $this->assertSame("<p>foo<br />\nbar</p>\n", $this->parseAndRender("foo  \nbar"));
    }

    public function testNoBreakNodeInsertedInSingleLineParagraph(): void
    {
        $this->assertSame("<p>foo bar</p>\n", $this->parseAndRender("foo bar"));
    }
}
