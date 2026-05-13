<?php

declare(strict_types=1);

namespace PhpMarkdown\Node\Inline;

use PhpMarkdown\Node\InlineNodeInterface;

/**
 * Represents GFM strikethrough (~~text~~) → <del>.
 */
final readonly class StrikethroughNode implements InlineNodeInterface
{
    public function __construct(
        /** @var InlineNodeInterface[] */
        public array $children = [],
    ) {
    }
}
