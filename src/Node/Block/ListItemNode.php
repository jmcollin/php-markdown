<?php

declare(strict_types=1);

namespace PhpMarkdown\Node\Block;

use PhpMarkdown\Node\BlockNodeInterface;
use PhpMarkdown\Node\InlineNodeInterface;

/**
 * Represents a single list item (<li>).
 */
final readonly class ListItemNode implements BlockNodeInterface
{
    public function __construct(
        /** @var InlineNodeInterface[] */
        public array $children = [],
    ) {
    }
}
