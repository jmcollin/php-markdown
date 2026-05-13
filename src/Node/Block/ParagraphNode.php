<?php

declare(strict_types=1);

namespace PhpMarkdown\Node\Block;

use PhpMarkdown\Node\BlockNodeInterface;
use PhpMarkdown\Node\InlineNodeInterface;

/**
 * Represents a Markdown paragraph.
 */
final readonly class ParagraphNode implements BlockNodeInterface
{
    public function __construct(
        /** @var InlineNodeInterface[] */
        public array $children = [],
    ) {
    }
}
