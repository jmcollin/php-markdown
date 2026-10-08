<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Integration;

use PhpMarkdown\MarkdownParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Link reference definitions are document-global, even inside containers (CommonMark §4.7).
 */
final class NestedLinkDefinitionTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function cases(): array
    {
        return [
            'definition in a blockquote, used before it' => [
                "[foo]\n\n> [foo]: /url",
                "<p><a href=\"/url\">foo</a></p>\n<blockquote>\n</blockquote>\n",
            ],
            'definition in a nested blockquote, with title' => [
                "> > [a]: /deep \"T\"\n\n[a]",
                "<blockquote>\n<blockquote>\n</blockquote>\n</blockquote>\n<p><a href=\"/deep\" title=\"T\">a</a></p>\n",
            ],
            'definition in a column' => [
                ":::columns\n[b]: /col\n|||\nx\n:::\n\n[b]",
                "<div class=\"grid grid-cols-2 gap-4\">\n<div class=\"min-w-0\">\n</div>\n<div class=\"min-w-0\">\n<p>x</p>\n</div>\n</div>\n<p><a href=\"/col\">b</a></p>\n",
            ],
            'used inside the same blockquote' => [
                "> [q]\n>\n> [q]: /in-quote",
                "<blockquote>\n<p><a href=\"/in-quote\">q</a></p>\n</blockquote>\n",
            ],
            'first definition wins across containers' => [
                "[x]: /first\n\n> [x]: /second\n\n[x]",
                "<blockquote>\n</blockquote>\n<p><a href=\"/first\">x</a></p>\n",
            ],
            'unsafe nested definition is still rejected' => [
                "> [js]: javascript:alert(1)\n\n[js]",
                "<blockquote>\n</blockquote>\n<p>[js]</p>\n",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testNestedDefinition(string $markdown, string $expected): void
    {
        $this->assertSame($expected, (new MarkdownParser())->parse($markdown));
    }
}
