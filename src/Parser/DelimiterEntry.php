<?php

declare(strict_types=1);

namespace PhpMarkdown\Parser;

/**
 * Mutable delimiter-stack entry used by DelimiterStack (one per '*' / '_' run).
 * An object with typed properties is several times smaller than the equivalent
 * associative array, which matters for inputs made of many delimiter runs.
 *
 * @internal
 */
final class DelimiterEntry
{
    public function __construct(
        public readonly string $char,
        public int $count,
        /** Run length before any match: used by the "multiple of 3" rule. */
        public readonly int $orig,
        public readonly bool $canOpen,
        public readonly bool $canClose,
    ) {
    }
}
