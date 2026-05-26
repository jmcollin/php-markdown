<?php

declare(strict_types=1);

namespace PhpMarkdown\Node\Inline;

use PhpMarkdown\Node\InlineNodeInterface;

/**
 * Represents a CommonMark autolink: <https://example.com> or <user@example.com>.
 * url stores the raw value (no encoding). isEmail drives mailto: prefix in renderer.
 */
final readonly class AutolinkNode implements InlineNodeInterface
{
    public function __construct(
        public string $url,
        public bool $isEmail = false,
    ) {
    }
}
