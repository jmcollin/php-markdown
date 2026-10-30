<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit\Renderer;

use PhpMarkdown\Node\Block\DocumentNode;
use PhpMarkdown\Node\NodeInterface;
use PhpMarkdown\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

/**
 * HtmlRenderer dispatches on the exact class of a node, for speed. A subclass of a known
 * node would therefore not be recognised: every node class must stay final.
 */
final class NodeDispatchTest extends TestCase
{
    public function testEveryNodeClassIsFinal(): void
    {
        $root  = dirname(__DIR__, 3) . '/src/Node';
        $files = [...glob($root . '/Block/*.php') ?: [], ...glob($root . '/Inline/*.php') ?: []];
        $this->assertNotSame([], $files);

        foreach ($files as $file) {
            $class = 'PhpMarkdown\\Node\\' . basename(dirname($file)) . '\\' . basename($file, '.php');
            $this->assertTrue((new \ReflectionClass($class))->isFinal(), $class . ' must be final');
        }
    }

    public function testUnknownNodeTypeIsRejected(): void
    {
        $node = new class implements NodeInterface {
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown node type');
        /** @psalm-suppress InvalidArgument */
        (new HtmlRenderer())->render(new DocumentNode([$node]));
    }
}
