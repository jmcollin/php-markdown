<?php

declare(strict_types=1);

namespace PhpMarkdown\Node\Inline;

use PhpMarkdown\Node\InlineNodeInterface;

/**
 * Represents an inline footnote reference: [^label].
 */
final readonly class FootnoteRefNode implements InlineNodeInterface
{
    public function __construct(
        public string $label,
        public int    $number,
        public int    $occurrence, // 1-based; 1 = first, 2 = second, etc.
    ) {
    }
}
