<?php

declare(strict_types=1);

namespace PhpMarkdown\Node\Inline;

use PhpMarkdown\Node\InlineNodeInterface;

/**
 * Represents an image (![alt](src "title"?)) → <img src="..." alt="..." title="...">.
 */
final readonly class ImageNode implements InlineNodeInterface
{
    public function __construct(
        public string $src,
        public string $alt = '',
        public ?string $title = null,
    ) {
    }
}
