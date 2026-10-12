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
                "<table>\n<thead>\n<tr>\n<th align=\"center\">a</th>\n</tr>\n</thead>\n<tbody>\n<tr>\n<td align=\"center\">b</td>\n</tr>\n</tbody>\n</table>\n",
            ],
            'escaped pipe stays in cell, also in code span' => [
                "| a | b |\n|---|---|\n| `x\\|y` | 1 \\| 2 |",
                "<table>\n<thead>\n<tr>\n<th>a</th>\n<th>b</th>\n</tr>\n</thead>\n<tbody>\n<tr>\n<td><code>x|y</code></td>\n<td>1 | 2</td>\n</tr>\n</tbody>\n</table>\n",
            ],
            'short rows padded, long rows truncated' => [
                "| a | b |\n|---|---|\n| 1 |\n| 1 | 2 | 3 |",
                "<table>\n<thead>\n<tr>\n<th>a</th>\n<th>b</th>\n</tr>\n</thead>\n<tbody>\n<tr>\n<td>1</td>\n<td></td>\n</tr>\n<tr>\n<td>1</td>\n<td>2</td>\n</tr>\n</tbody>\n</table>\n",
            ],
            'table ends at another block' => [
                "a | b\n--- | ---\nc | d\n# h",
                "<table>\n<thead>\n<tr>\n<th>a</th>\n<th>b</th>\n</tr>\n</thead>\n<tbody>\n<tr>\n<td>c</td>\n<td>d</td>\n</tr>\n</tbody>\n</table>\n<h1>h</h1>\n",
            ],
            'table after a paragraph line' => [
                "para\na | b\n-|-\n1|2",
                "<p>para</p>\n<table>\n<thead>\n<tr>\n<th>a</th>\n<th>b</th>\n</tr>\n</thead>\n<tbody>\n<tr>\n<td>1</td>\n<td>2</td>\n</tr>\n</tbody>\n</table>\n",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testTable(string $markdown, string $expected): void
    {
        $this->assertSame($expected, (new MarkdownParser())->parse($markdown));
    }
}
