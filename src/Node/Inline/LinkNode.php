<?php

declare(strict_types=1);

namespace PhpMarkdown\Node\Inline;

use PhpMarkdown\Node\InlineNodeInterface;

/**
 * Represents a hyperlink ([text](url "title")) → <a href="...">.
 */
final readonly class LinkNode implements InlineNodeInterface
{
    public function __construct(
        public string $href,
        /** @var InlineNodeInterface[] */
        public array $children = [],
        public ?string $title = null,
    ) {
    }
}
