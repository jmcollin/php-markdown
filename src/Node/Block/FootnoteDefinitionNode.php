<?php

declare(strict_types=1);

namespace PhpMarkdown\Node\Block;

use PhpMarkdown\Node\BlockNodeInterface;
use PhpMarkdown\Node\InlineNodeInterface;

/**
 * Represents a resolved footnote definition entry (rendered inside FootnotesContainerNode).
 */
final readonly class FootnoteDefinitionNode implements BlockNodeInterface
{
    public function __construct(
        public string $label,
        public int    $number,
        /** @var InlineNodeInterface[] */
        public array  $children,
        /** @var list<string> e.g. ['fnref-1', 'fnref-1-2'] */
        public array  $backLinkIds,
    ) {
    }
}
