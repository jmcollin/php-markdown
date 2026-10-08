<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Integration;

use PhpMarkdown\MarkdownParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * List items and lists (CommonMark §5.2–5.3): start number, delimiters, nesting by
 * content column, continuation lines, 4-space indentation.
 */
final class ListItemTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function cases(): array
    {
        return [
            'ordered list keeps its start number' => [
                "3. three\n4. four",
                "<ol start=\"3\">\n<li>three</li>\n<li>four</li>\n</ol>\n",
            ],
            'closing parenthesis delimiter' => [
                "1) a\n2) b",
                "<ol>\n<li>a</li>\n<li>b</li>\n</ol>\n",
            ],
            'indented and lazy continuation lines' => [
                "- item\n  continued\nlazy\n- b",
                "<ul>\n<li>item\ncontinued\nlazy</li>\n<li>b</li>\n</ul>\n",
            ],
            'changing bullet char starts a new list' => [
                "- a\n+ b",
                "<ul>\n<li>a</li>\n</ul>\n<ul>\n<li>b</li>\n</ul>\n",
            ],
            'changing ordered delimiter starts a new list' => [
                "1. a\n1) b",
                "<ol>\n<li>a</li>\n</ol>\n<ol>\n<li>b</li>\n</ol>\n",
            ],
            'ordered item not starting at 1 cannot interrupt a paragraph' => [
                "para\n2. not a list",
                "<p>para\n2. not a list</p>\n",
            ],
            'four-space marker outside a list is code' => [
                '    - foo',
                "<pre><code>- foo\n</code></pre>\n",
            ],
            'nesting follows the content column of wide markers' => [
                "10. a\n    - b\n11. c",
                "<ol start=\"10\">\n<li>a<ul>\n<li>b</li>\n</ul>\n</li>\n<li>c</li>\n</ol>\n",
            ],
            'not enough indentation is a sibling, not a child' => [
                "- a\n - b",
                "<ul>\n<li>a</li>\n<li>b</li>\n</ul>\n",
            ],
            'heading after an item is not a continuation' => [
                "- a\n# b",
                "<ul>\n<li>a</li>\n</ul>\n<h1>b</h1>\n",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testList(string $markdown, string $expected): void
    {
        $this->assertSame($expected, (new MarkdownParser())->parse($markdown));
    }

    public function testManyContinuationLinesStayLinear(): void
    {
        $start = microtime(true);
        (new MarkdownParser())->parse("- a\n" . str_repeat("b\n", 200_000));
        (new MarkdownParser())->parse(str_repeat("\n", 500_000));
        $this->assertLessThan(5.0, microtime(true) - $start);
    }
}
