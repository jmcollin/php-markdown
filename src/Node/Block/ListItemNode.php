<?php

declare(strict_types=1);

namespace PhpMarkdown\Node\Block;

use PhpMarkdown\Node\BlockNodeInterface;

/**
 * Represents a single list item (<li>).
 */
final readonly class ListItemNode implements BlockNodeInterface
{
    public function __construct(
        /** @var \PhpMarkdown\Node\NodeInterface[] */
        public array $children = [],
        public bool $loose = false,
        public ?bool $checked = null,
    ) {
    }
}
