<?php

declare(strict_types=1);

namespace PhpMarkdown\Node\Block;

use PhpMarkdown\Node\BlockNodeInterface;

/**
 * Represents the footnotes section appended at the end of the document.
 */
final readonly class FootnotesContainerNode implements BlockNodeInterface
{
    public function __construct(
        /** @var FootnoteDefinitionNode[] ordered by first-appearance number */
        public array $definitions,
    ) {
    }
}
