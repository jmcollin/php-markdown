<?php

declare(strict_types=1);

namespace PhpMarkdown\Node\Block;

use PhpMarkdown\Node\BlockNodeInterface;

/**
 * Represents a fenced code block (```lang ... ```).
 */
final readonly class FencedCodeNode implements BlockNodeInterface
{
    public function __construct(
        public string $content,
        public string $language = '',
    ) {
    }
}
