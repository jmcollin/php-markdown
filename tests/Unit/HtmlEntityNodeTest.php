<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit;

use PhpMarkdown\Node\Inline\HtmlEntityNode;
use PHPUnit\Framework\TestCase;

final class HtmlEntityNodeTest extends TestCase
{
    public function testEntityPropertyHoldsRawEntity(): void
    {
        $node = new HtmlEntityNode('&amp;');

        $this->assertSame('&amp;', $node->entity);
    }
}
