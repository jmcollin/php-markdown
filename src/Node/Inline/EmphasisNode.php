<?php

declare(strict_types=1);

namespace PhpMarkdown\Node\Inline;

use PhpMarkdown\Node\InlineNodeInterface;

/**
 * Represents italic emphasis (*text* or _text_) → <em>.
 */
final readonly class EmphasisNode implements InlineNodeInterface
{
    public function __construct(
        /** @var InlineNodeInterface[] */
        public array $children = [],
    ) {
    }
}
