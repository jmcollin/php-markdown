<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Integration;

use PhpMarkdown\MarkdownParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Block quotes (CommonMark §5.1): lazy continuation lines and indented markers.
 */
final class BlockquoteLazyContinuationTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function cases(): array
    {
        return [
            'lazy line continues paragraph' => [
                "> bar\nbaz",
                "<blockquote>\n<p>bar\nbaz</p>\n</blockquote>\n",
            ],
            'lazy line between quote lines' => [
                "> bar\nbaz\n> foo",
                "<blockquote>\n<p>bar\nbaz\nfoo</p>\n</blockquote>\n",
            ],
            'lazy line in nested quote' => [
                "> > > foo\nbar",
                "<blockquote>\n<blockquote>\n<blockquote>\n<p>foo\nbar</p>\n</blockquote>\n</blockquote>\n</blockquote>\n",
            ],
            'shallower marker is lazy too' => [
                ">>> foo\n> bar\n>>baz",
                "<blockquote>\n<blockquote>\n<blockquote>\n<p>foo\nbar\nbaz</p>\n</blockquote>\n</blockquote>\n</blockquote>\n",
            ],
            'indented markers' => [
                "   > # Foo\n   > bar\n > baz",
                "<blockquote>\n<h1>Foo</h1>\n<p>bar\nbaz</p>\n</blockquote>\n",
            ],
            'blank line ends the quote' => [
                "> foo\n\nbar",
                "<blockquote>\n<p>foo</p>\n</blockquote>\n<p>bar</p>\n",
            ],
            'thematic break is not lazy' => [
                "> foo\n---",
                "<blockquote>\n<p>foo</p>\n</blockquote>\n<hr />\n",
            ],
            'heading is not lazy' => [
                "> foo\n# bar",
                "<blockquote>\n<p>foo</p>\n</blockquote>\n<h1>bar</h1>\n",
            ],
            'no lazy line after a heading' => [
                "> # foo\nbar",
                "<blockquote>\n<h1>foo</h1>\n</blockquote>\n<p>bar</p>\n",
            ],
            'no lazy line inside a fence' => [
                "> ```\nfoo\n```",
                "<blockquote>\n<pre><code></code></pre>\n</blockquote>\n<p>foo</p>\n<pre><code></code></pre>\n",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testBlockquote(string $markdown, string $expected): void
    {
        $this->assertSame($expected, (new MarkdownParser())->parse($markdown));
    }
}
