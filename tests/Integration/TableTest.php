<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Integration;

use PhpMarkdown\MarkdownParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * GFM tables (§4.10): detection requires a valid delimiter row; escaped pipes; cell count.
 */
final class TableTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function cases(): array
    {
        return [
            'pipes in prose are not a table' => [
                'I like cats | dogs | birds',
                "<p>I like cats | dogs | birds</p>\n",
            ],
            'delimiter cell count must match header' => [
                "| a | b |\n| --- |\n| c |",
                "<p>| a | b |\n| --- |\n| c |</p>\n",
            ],
            'single column table' => [
                "| a |\n| :-: |\n| b |",
                '<table><thead><tr><th align="center">a</th></tr></thead><tbody><tr><td align="center">b</td></tr></tbody></table>',
            ],
            'escaped pipe stays in cell, also in code span' => [
                "| a | b |\n|---|---|\n| `x\\|y` | 1 \\| 2 |",
                '<table><thead><tr><th>a</th><th>b</th></tr></thead><tbody><tr><td><code>x|y</code></td><td>1 | 2</td></tr></tbody></table>',
            ],
            'short rows padded, long rows truncated' => [
                "| a | b |\n|---|---|\n| 1 |\n| 1 | 2 | 3 |",
                '<table><thead><tr><th>a</th><th>b</th></tr></thead><tbody><tr><td>1</td><td></td></tr><tr><td>1</td><td>2</td></tr></tbody></table>',
            ],
            'table ends at another block' => [
                "a | b\n--- | ---\nc | d\n# h",
                "<table><thead><tr><th>a</th><th>b</th></tr></thead><tbody><tr><td>c</td><td>d</td></tr></tbody></table><h1>h</h1>\n",
            ],
            'table after a paragraph line' => [
                "para\na | b\n-|-\n1|2",
                "<p>para</p>\n<table><thead><tr><th>a</th><th>b</th></tr></thead><tbody><tr><td>1</td><td>2</td></tr></tbody></table>",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testTable(string $markdown, string $expected): void
    {
        $this->assertSame($expected, (new MarkdownParser())->parse($markdown));
    }
}
