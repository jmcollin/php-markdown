<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Integration;

use PhpMarkdown\MarkdownParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ATX headings (CommonMark §4.2): leading indentation, empty headings, closing sequences.
 */
final class AtxHeadingTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function cases(): array
    {
        return [
            'one to three leading spaces' => [" ### foo\n  ## foo\n   # foo", "<h3>foo</h3>\n<h2>foo</h2>\n<h1>foo</h1>\n"],
            'four spaces is code'         => ['    # foo', "<pre><code># foo\n</code></pre>\n"],
            'empty headings'              => ["## \n#\n### ###", "<h2></h2>\n<h1></h1>\n<h3></h3>\n"],
            'indented closing sequence'   => ["## foo ##\n  ###   bar    ###", "<h2>foo</h2>\n<h3>bar</h3>\n"],
            'closing needs a space'       => ['# foo#', "<h1>foo#</h1>\n"],
            'escaped closing sequence'    => ['### foo \###', "<h3>foo ###</h3>\n"],
            'no space after hashes'       => ['#5 bolt', "<p>#5 bolt</p>\n"],
            'seven hashes'                => ['####### foo', "<p>####### foo</p>\n"],
        ];
    }

    #[DataProvider('cases')]
    public function testAtxHeading(string $markdown, string $expected): void
    {
        $this->assertSame($expected, (new MarkdownParser())->parse($markdown));
    }
}
