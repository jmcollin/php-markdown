<?php

declare(strict_types=1);

namespace PhpMarkdown\Node\Inline;

use PhpMarkdown\Node\InlineNodeInterface;

/**
 * Raw text content. MUST be escaped by the renderer (XSS protection).
 */
final readonly class TextNode implements InlineNodeInterface
{
    public function __construct(
        public string $text,
    ) {
    }
}
