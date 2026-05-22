<?php

declare(strict_types=1);

namespace PhpMarkdown\Node\Inline;

use PhpMarkdown\Node\InlineNodeInterface;

/**
 * A single raw inline HTML tag exactly as matched by the inline scanner (CommonMark §6.6).
 * The renderer is responsible for escaping (S29 fallback) or pass-through (S31).
 */
final readonly class RawHtmlInlineNode implements InlineNodeInterface
{
    public function __construct(
        public string $content,
    ) {
    }
}
