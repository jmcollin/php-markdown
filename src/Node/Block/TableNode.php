<?php

declare(strict_types=1);

namespace PhpMarkdown\Node\Block;

use PhpMarkdown\Node\BlockNodeInterface;

/**
 * Represents a GFM table (<table>).
 */
final readonly class TableNode implements BlockNodeInterface
{
    public function __construct(
        /** @var TableRowNode[] */
        public array $rows = [],
    ) {
    }
}
