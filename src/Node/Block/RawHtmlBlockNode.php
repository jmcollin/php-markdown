<?php

declare(strict_types=1);

namespace PhpMarkdown\Node\Block;

use PhpMarkdown\Node\BlockNodeInterface;

/**
 * Represents a raw HTML block (CommonMark §4.6).
 *
 * Content is emitted verbatim by the renderer — no escaping applied.
 */
final readonly class RawHtmlBlockNode implements BlockNodeInterface
{
    public function __construct(
        public string $content,
    ) {
    }
}
