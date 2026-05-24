<?php

declare(strict_types=1);

namespace PhpMarkdown\Node\Block;

use PhpMarkdown\Node\BlockNodeInterface;

final readonly class IndentedCodeNode implements BlockNodeInterface
{
    public function __construct(
        public string $content,
    ) {
    }
}
