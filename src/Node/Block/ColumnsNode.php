<?php

declare(strict_types=1);

namespace PhpMarkdown\Node\Block;

use PhpMarkdown\Node\BlockNodeInterface;

/**
 * Represents a two-column layout container (:::columns ... ||| ... :::).
 */
final readonly class ColumnsNode implements BlockNodeInterface
{
    public function __construct(
        /** @var BlockNodeInterface[] */
        public array $leftChildren = [],
        /** @var BlockNodeInterface[] */
        public array $rightChildren = [],
    ) {
    }
}
