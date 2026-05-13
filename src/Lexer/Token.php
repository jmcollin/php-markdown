<?php

declare(strict_types=1);

namespace PhpMarkdown\Lexer;

/**
 * Immutable token produced by the Lexer.
 */
final readonly class Token
{
    public function __construct(
        public TokenType $type,
        public string $content,
        /** @var array<string, mixed> */
        public array $meta = [],
    ) {
    }
}
