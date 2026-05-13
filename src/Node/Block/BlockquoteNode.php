<?php

declare(strict_types=1);

namespace PhpMarkdown\Node\Block;

use PhpMarkdown\Node\BlockNodeInterface;

/**
 * Represents a Markdown blockquote (> ...).
 */
final readonly class BlockquoteNode implements BlockNodeInterface
{
    public function __construct(
        /** @var BlockNodeInterface[] */
        public array $children = [],
    ) {
    }
}
