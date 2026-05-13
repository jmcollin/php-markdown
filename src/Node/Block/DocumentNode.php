<?php

declare(strict_types=1);

namespace PhpMarkdown\Node\Block;

use PhpMarkdown\Node\BlockNodeInterface;
use PhpMarkdown\Node\NodeInterface;

/**
 * Root node of the AST. Contains all block-level children.
 */
final readonly class DocumentNode implements BlockNodeInterface
{
    public function __construct(
        /** @var NodeInterface[] */
        public array $children = [],
    ) {
    }
}
