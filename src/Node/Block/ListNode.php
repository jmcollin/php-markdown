<?php

declare(strict_types=1);

namespace PhpMarkdown\Node\Block;

use PhpMarkdown\Node\BlockNodeInterface;

/**
 * Represents an ordered (<ol>) or unordered (<ul>) list.
 */
final readonly class ListNode implements BlockNodeInterface
{
    public function __construct(
        public bool $ordered,
        /** @var ListItemNode[] */
        public array $children = [],
    ) {
    }
}
