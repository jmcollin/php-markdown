<?php

declare(strict_types=1);

namespace PhpMarkdown\Node\Block;

use PhpMarkdown\Node\BlockNodeInterface;

/**
 * Represents a table row (<tr>), either in <thead> or <tbody>.
 */
final readonly class TableRowNode implements BlockNodeInterface
{
    public function __construct(
        /** @var TableCellNode[] */
        public array $cells = [],
        public bool $isHeader = false,
    ) {
    }
}
