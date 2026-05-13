<?php

declare(strict_types=1);

namespace PhpMarkdown\Node\Inline;

use PhpMarkdown\Node\InlineNodeInterface;

/**
 * Represents inline code (`code`) → <code>.
 */
final readonly class CodeNode implements InlineNodeInterface
{
    public function __construct(
        public string $code,
    ) {
    }
}
