<?php

declare(strict_types=1);

namespace PhpMarkdown\Node\Inline;

use PhpMarkdown\Node\InlineNodeInterface;

/**
 * Represents bold text (**text** or __text__) → <strong>.
 */
final readonly class StrongNode implements InlineNodeInterface
{
    public function __construct(
        /** @var InlineNodeInterface[] */
        public array $children = [],
    ) {
    }
}
