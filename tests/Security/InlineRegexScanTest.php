<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Security;

use PhpMarkdown\MarkdownParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Inline patterns run at many offsets of the same text. Each match must cost O(1) when
 * it fails: PCRE's start-of-match optimisation used to scan the rest of the text for a
 * required character ('>', ';', ']'), making these inputs quadratic (~2.5 s for 400 KB).
 */
final class InlineRegexScanTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function inputs(): array
    {
        return [
            'many "<" without ">"'   => [str_repeat('< a ', 100_000)],
            'many "&" without ";"'   => [str_repeat('&amp ', 80_000)],
            'many "[^" without "]"'  => [str_repeat('[^a ', 100_000)],
            'many "<x" without ">"'  => [str_repeat('<x ', 130_000)],
        ];
    }

    #[DataProvider('inputs')]
    public function testFailedInlineMatchesStayCheap(string $markdown): void
    {
        $start = microtime(true);
        (new MarkdownParser())->parse($markdown);
        $this->assertLessThan(1.0, microtime(true) - $start);
    }
}
