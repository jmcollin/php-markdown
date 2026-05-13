<?php

declare(strict_types=1);

namespace PhpMarkdown\Node\Block;

use PhpMarkdown\Exception\ParseException;
use PhpMarkdown\Node\BlockNodeInterface;
use PhpMarkdown\Node\InlineNodeInterface;

/**
 * Represents a Markdown heading (# to ######).
 */
final readonly class HeadingNode implements BlockNodeInterface
{
    public function __construct(
        public int $level,
        /** @var InlineNodeInterface[] */
        public array $children = [],
    ) {
        if ($level < 1 || $level > 6) {
            throw new ParseException("Heading level must be between 1 and 6, got {$level}.");
        }
    }
}
