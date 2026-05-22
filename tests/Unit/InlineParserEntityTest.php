<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit;

use PhpMarkdown\Node\Inline\CodeNode;
use PhpMarkdown\Node\Inline\HtmlEntityNode;
use PhpMarkdown\Node\Inline\LinkNode;
use PhpMarkdown\Parser\InlineParser;
use PHPUnit\Framework\TestCase;

final class InlineParserEntityTest extends TestCase
{
    private InlineParser $parser;

    protected function setUp(): void
    {
        $this->parser = new InlineParser();
    }

    public function testNamedEntityProducesHtmlEntityNode(): void
    {
        $nodes = $this->parser->parse('&amp;');

        $this->assertCount(1, $nodes);
        $this->assertInstanceOf(HtmlEntityNode::class, $nodes[0]);
        $this->assertSame('&amp;', $nodes[0]->entity);
    }

    public function testDecimalEntityProducesHtmlEntityNode(): void
    {
        $nodes = $this->parser->parse('&#160;');

        $this->assertCount(1, $nodes);
        $this->assertInstanceOf(HtmlEntityNode::class, $nodes[0]);
        $this->assertSame('&#160;', $nodes[0]->entity);
    }

    public function testHexEntityProducesHtmlEntityNode(): void
    {
        $nodes = $this->parser->parse('&#x00A0;');

        $this->assertCount(1, $nodes);
        $this->assertInstanceOf(HtmlEntityNode::class, $nodes[0]);
        $this->assertSame('&#x00A0;', $nodes[0]->entity);
    }

    public function testBareAmpersandProducesTextNode(): void
    {
        $nodes = $this->parser->parse('foo & bar');

        // Should produce one or more nodes but none must be HtmlEntityNode.
        foreach ($nodes as $node) {
            $this->assertNotInstanceOf(HtmlEntityNode::class, $node);
        }
    }

    public function testInvalidNamedEntityProducesTextNode(): void
    {
        $nodes = $this->parser->parse('&notanentity;');

        // The whole token must end up as text, not an HtmlEntityNode.
        foreach ($nodes as $node) {
            $this->assertNotInstanceOf(HtmlEntityNode::class, $node);
        }
    }

    public function testNullCodepointEntityProducesTextNode(): void
    {
        $nodes = $this->parser->parse('&#0;');

        foreach ($nodes as $node) {
            $this->assertNotInstanceOf(HtmlEntityNode::class, $node);
        }
    }

    public function testSurrogateEntityProducesTextNode(): void
    {
        $nodes = $this->parser->parse('&#xD800;');

        foreach ($nodes as $node) {
            $this->assertNotInstanceOf(HtmlEntityNode::class, $node);
        }
    }

    public function testCodeSpanContentIsNotScannedForEntities(): void
    {
        $nodes = $this->parser->parse('`&amp;`');

        $this->assertCount(1, $nodes);
        $this->assertInstanceOf(CodeNode::class, $nodes[0]);
        $this->assertSame('&amp;', $nodes[0]->code);
    }

    public function testLtAndGtEntitiesProduceHtmlEntityNode(): void
    {
        $nodesLt = $this->parser->parse('&lt;');
        $nodesGt = $this->parser->parse('&gt;');

        $this->assertCount(1, $nodesLt);
        $this->assertInstanceOf(HtmlEntityNode::class, $nodesLt[0]);
        $this->assertSame('&lt;', $nodesLt[0]->entity);

        $this->assertCount(1, $nodesGt);
        $this->assertInstanceOf(HtmlEntityNode::class, $nodesGt[0]);
        $this->assertSame('&gt;', $nodesGt[0]->entity);
    }

    public function testEntityAtStartOfInput(): void
    {
        $nodes = $this->parser->parse('&amp; text');

        $this->assertInstanceOf(HtmlEntityNode::class, $nodes[0]);
        $this->assertSame('&amp;', $nodes[0]->entity);
    }

    public function testEntityAtEndOfInput(): void
    {
        $nodes = $this->parser->parse('text &amp;');

        $last = $nodes[array_key_last($nodes)];
        $this->assertInstanceOf(HtmlEntityNode::class, $last);
        $this->assertSame('&amp;', $last->entity);
    }

    public function testEntityInsideLinkText(): void
    {
        $nodes = $this->parser->parse('[foo &amp; bar](https://example.com)');

        $this->assertCount(1, $nodes);
        $this->assertInstanceOf(LinkNode::class, $nodes[0]);

        $children = $nodes[0]->children;
        $entityNodes = array_filter($children, fn($n) => $n instanceof HtmlEntityNode);
        $this->assertNotEmpty($entityNodes);
        $entity = array_values($entityNodes)[0];
        $this->assertSame('&amp;', $entity->entity);
    }

    public function testLargeDecimalCodepointEntityProducesTextNode(): void
    {
        $nodes = $this->parser->parse('&#2000000;');

        foreach ($nodes as $node) {
            $this->assertNotInstanceOf(HtmlEntityNode::class, $node);
        }
    }
}
