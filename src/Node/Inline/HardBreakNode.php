<?php

declare(strict_types=1);

namespace PhpMarkdown\Node\Inline;

use PhpMarkdown\Node\InlineNodeInterface;

/**
 * Represents a hard line break → <br>.
 *
 * Leaf node: no children, no escaping.
 * Produced by two trailing spaces or a backslash before a newline (CommonMark §6.7).
 */
final readonly class HardBreakNode implements InlineNodeInterface
{
}
