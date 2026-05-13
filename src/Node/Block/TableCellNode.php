<?php

declare(strict_types=1);

namespace PhpMarkdown\Node\Block;

use PhpMarkdown\Node\BlockNodeInterface;
use PhpMarkdown\Node\InlineNodeInterface;

/**
 * Represents a table cell (<th> or <td>).
 */
final readonly class TableCellNode implements BlockNodeInterface
{
    public function __construct(
        /** @var InlineNodeInterface[] */
        public array $children = [],
        public string $align = '',
    ) {
    }
}
