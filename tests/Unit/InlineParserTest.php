<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit;

use PhpMarkdown\Node\Inline\CodeNode;
use PhpMarkdown\Node\Inline\EmphasisNode;
use PhpMarkdown\Node\Inline\ImageNode;
use PhpMarkdown\Node\Inline\LinkNode;
use PhpMarkdown\Node\Inline\StrikethroughNode;
use PhpMarkdown\Node\Inline\StrongNode;
use PhpMarkdown\Node\Inline\TextNode;
use PhpMarkdown\Parser\InlineParser;
use PHPUnit\Framework\TestCase;

final class InlineParserTest extends TestCase
{
    private InlineParser $parser;

    protected function setUp(): void
    {
        $this->parser = new InlineParser();
    }

    public function testPlainTextProducesSingleTextNode(): void
    {
        $nodes = $this->parser->parse('hello');

        $this->assertCount(1, $nodes);
        $this->assertInstanceOf(TextNode::class, $nodes[0]);
        $this->assertSame('hello', $nodes[0]->text);
    }

    public function testBoldAsterisks(): void
    {
        $nodes = $this->parser->parse('**bold**');

        $this->assertCount(1, $nodes);
        $this->assertInstanceOf(StrongNode::class, $nodes[0]);
        $this->assertInstanceOf(TextNode::class, $nodes[0]->children[0]);
        $this->assertSame('bold', $nodes[0]->children[0]->text);
    }

    public function testBoldUnderscores(): void
    {
        $nodes = $this->parser->parse('__bold__');

        $this->assertInstanceOf(StrongNode::class, $nodes[0]);
    }

    public function testItalicAsterisks(): void
    {
        $nodes = $this->parser->parse('*italic*');

        $this->assertInstanceOf(EmphasisNode::class, $nodes[0]);
        $this->assertSame('italic', $nodes[0]->children[0]->text);
    }

    public function testItalicUnderscores(): void
    {
        $nodes = $this->parser->parse('_italic_');

        $this->assertInstanceOf(EmphasisNode::class, $nodes[0]);
    }

    public function testLink(): void
    {
        $nodes = $this->parser->parse('[text](https://example.com)');

        $this->assertInstanceOf(LinkNode::class, $nodes[0]);
        $this->assertSame('https://example.com', $nodes[0]->href);
        $this->assertNull($nodes[0]->title);
    }

    public function testLinkWithTitle(): void
    {
        $nodes = $this->parser->parse('[text](https://example.com "My Title")');

        $this->assertInstanceOf(LinkNode::class, $nodes[0]);
        $this->assertSame('My Title', $nodes[0]->title);
    }

    public function testImage(): void
    {
        $nodes = $this->parser->parse('![alt](img.png)');

        $this->assertInstanceOf(ImageNode::class, $nodes[0]);
        $this->assertSame('img.png', $nodes[0]->src);
        $this->assertSame('alt', $nodes[0]->alt);
    }

    public function testInlineCode(): void
    {
        $nodes = $this->parser->parse('`echo`');

        $this->assertInstanceOf(CodeNode::class, $nodes[0]);
        $this->assertSame('echo', $nodes[0]->code);
    }

    public function testMixedContent(): void
    {
        $nodes = $this->parser->parse('Hello **world** and *you*');

        $this->assertCount(4, $nodes);
        $this->assertInstanceOf(TextNode::class, $nodes[0]);
        $this->assertInstanceOf(StrongNode::class, $nodes[1]);
        $this->assertInstanceOf(TextNode::class, $nodes[2]);
        $this->assertInstanceOf(EmphasisNode::class, $nodes[3]);
    }

    public function testNestedEmphasisInsideStrong(): void
    {
        $nodes = $this->parser->parse('**bold _italic_ bold**');

        $this->assertInstanceOf(StrongNode::class, $nodes[0]);
        $this->assertInstanceOf(EmphasisNode::class, $nodes[0]->children[1]);
    }

    public function testUnclosedBoldIsLiteralText(): void
    {
        $nodes = $this->parser->parse('**unclosed');

        $this->assertInstanceOf(TextNode::class, $nodes[0]);
        $this->assertSame('**unclosed', $nodes[0]->text);
    }

    public function testJavascriptUrlBlocked(): void
    {
        $nodes = $this->parser->parse('[x](javascript:alert(1))');

        $this->assertInstanceOf(TextNode::class, $nodes[0]);
    }

    public function testVbscriptUrlBlocked(): void
    {
        $nodes = $this->parser->parse('[x](vbscript:msgbox(1))');

        $this->assertInstanceOf(TextNode::class, $nodes[0]);
    }

    public function testDataUrlBlocked(): void
    {
        $nodes = $this->parser->parse('[x](data:text/html,xss)');

        $this->assertInstanceOf(TextNode::class, $nodes[0]);
    }

    public function testHttpsUrlAllowed(): void
    {
        $nodes = $this->parser->parse('[x](https://safe.example.com)');

        $this->assertInstanceOf(LinkNode::class, $nodes[0]);
    }

    public function testRelativeUrlAllowed(): void
    {
        $nodes = $this->parser->parse('[x](/about)');

        $this->assertInstanceOf(LinkNode::class, $nodes[0]);
    }

    public function testImageJavascriptSrcBlocked(): void
    {
        // Unsafe src must not produce an ImageNode — rendered as literal text instead.
        $nodes = $this->parser->parse('![alt](javascript:alert(1))');

        $this->assertInstanceOf(TextNode::class, $nodes[0]);
    }

    public function testImageDataSrcBlocked(): void
    {
        $nodes = $this->parser->parse('![alt](data:text/html,xss)');

        $this->assertInstanceOf(TextNode::class, $nodes[0]);
    }

    public function testImageControlCharUrlBlocked(): void
    {
        // Null byte in URL — must be rejected
        $nodes = $this->parser->parse("![alt](https://ok\x00evil.com)");

        // \x00 is in [^)"\s] charset, captured by regex — isSafeUrl must reject it
        $this->assertCount(1, $nodes);
        $this->assertInstanceOf(TextNode::class, $nodes[0]);
    }

    public function testImageHttpsSrcAllowed(): void
    {
        $nodes = $this->parser->parse('![photo](https://example.com/img.png)');

        $this->assertInstanceOf(ImageNode::class, $nodes[0]);
    }

    public function testImageAngleBracketUrlBlocked(): void
    {
        // <javascript:...> style bypass must not produce ImageNode
        $nodes = $this->parser->parse('![x](<javascript:alert(1)>)');

        // Angle brackets excluded from URL capture — the whole thing is a TextNode
        $this->assertCount(1, $nodes);
        $this->assertInstanceOf(TextNode::class, $nodes[0]);
    }

    public function testStrikethroughSimple(): void
    {
        $nodes = $this->parser->parse('~~foo~~');

        $this->assertCount(1, $nodes);
        $this->assertInstanceOf(StrikethroughNode::class, $nodes[0]);
        $this->assertInstanceOf(TextNode::class, $nodes[0]->children[0]);
        $this->assertSame('foo', $nodes[0]->children[0]->text);
    }

    public function testStrikethroughUnclosed(): void
    {
        $nodes = $this->parser->parse('~~unclosed');

        $this->assertCount(1, $nodes);
        $this->assertInstanceOf(TextNode::class, $nodes[0]);
        $this->assertSame('~~unclosed', $nodes[0]->text);
    }

    public function testStrikethroughSingleTildeNotAffected(): void
    {
        $nodes = $this->parser->parse('~not~');

        $this->assertCount(1, $nodes);
        $this->assertInstanceOf(TextNode::class, $nodes[0]);
        $this->assertSame('~not~', $nodes[0]->text);
    }

    public function testStrikethroughEmpty(): void
    {
        $nodes = $this->parser->parse('~~~~');

        $this->assertCount(1, $nodes);
        $this->assertInstanceOf(TextNode::class, $nodes[0]);
        $this->assertSame('~~~~', $nodes[0]->text);
    }

    public function testStrikethroughWithNestedStrong(): void
    {
        $nodes = $this->parser->parse('~~**bold**~~');

        $this->assertCount(1, $nodes);
        $this->assertInstanceOf(StrikethroughNode::class, $nodes[0]);
        $this->assertInstanceOf(StrongNode::class, $nodes[0]->children[0]);
        $this->assertSame('bold', $nodes[0]->children[0]->children[0]->text);
    }

    public function testStrikethroughMixedSurroundingText(): void
    {
        $nodes = $this->parser->parse('foo ~~bar~~ baz');

        $this->assertCount(3, $nodes);
        $this->assertInstanceOf(TextNode::class, $nodes[0]);
        $this->assertSame('foo ', $nodes[0]->text);
        $this->assertInstanceOf(StrikethroughNode::class, $nodes[1]);
        $this->assertSame('bar', $nodes[1]->children[0]->text);
        $this->assertInstanceOf(TextNode::class, $nodes[2]);
        $this->assertSame(' baz', $nodes[2]->text);
    }

    public function testDepthLimitDoesNotCrash(): void
    {
        // Deeply nested emphasis must not overflow the stack
        $input = str_repeat('*', 70) . 'x' . str_repeat('*', 70);
        $nodes = $this->parser->parse($input);
        $this->assertNotEmpty($nodes);
    }

    public function testProtocolRelativeUrlBlocked(): void
    {
        // //evil.com inherits caller's scheme — must not produce a LinkNode
        $nodes = $this->parser->parse('[click](//evil.com/steal)');

        $this->assertInstanceOf(TextNode::class, $nodes[0]);
    }

    public function testProtocolRelativeImageBlocked(): void
    {
        $nodes = $this->parser->parse('![x](//evil.com/img.png)');

        $this->assertInstanceOf(TextNode::class, $nodes[0]);
    }

    public function testParseUrlFalseBlocked(): void
    {
        // /// makes parse_url() return false; also starts with // so doubly blocked
        $nodes = $this->parser->parse('[x](///)');

        $this->assertInstanceOf(TextNode::class, $nodes[0]);
    }
}
