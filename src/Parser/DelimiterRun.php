<?php

declare(strict_types=1);

namespace PhpMarkdown\Parser;

/**
 * Represents a run of delimiter characters ('*' or '_') in the inline token stream.
 *
 * A delimiter run is a sequence of one or more identical delimiter chars. Its
 * canOpen / canClose flags are computed once by FlankingComputer and never mutate.
 * When the count is decremented during resolution a new instance is created.
 */
final readonly class DelimiterRun
{
    public function __construct(
        public string $char,
        public int    $count,
        public bool   $canOpen,
        public bool   $canClose,
    ) {
    }
}
