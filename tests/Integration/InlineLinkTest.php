<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Integration;

use PhpMarkdown\MarkdownParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Links and images (CommonMark §6.3–6.4): bracket matching, destinations, references.
 */
final class InlineLinkTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function cases(): array
    {
        return [
            'no link inside a link' => [
                '[a [b](/x) c](/y)',
                "<p>[a <a href=\"/x\">b</a> c](/y)</p>\n",
            ],
            'brackets inside link text' => [
                '[link [foo [bar]]](/uri)',
                "<p><a href=\"/uri\">link [foo [bar]]</a></p>\n",
            ],
            'image inside a link' => [
                '[![moon](moon.jpg)](/uri)',
                "<p><a href=\"/uri\"><img src=\"moon.jpg\" alt=\"moon\" /></a></p>\n",
            ],
            'image openers stay active after an inner link' => [
                '![[[foo](uri1)](uri2)](uri3)',
                "<p><img src=\"uri3\" alt=\"[foo](uri2)\" /></p>\n",
            ],
            'balanced parentheses in destination' => [
                '[link](foo(and(bar)))',
                "<p><a href=\"foo(and(bar))\">link</a></p>\n",
            ],
            'unbalanced parentheses are not a link' => [
                '[link](foo(and(bar))',
                "<p>[link](foo(and(bar))</p>\n",
            ],
            'escaped parentheses in destination' => [
                '[link](foo\(and\(bar\))',
                "<p><a href=\"foo(and(bar)\">link</a></p>\n",
            ],
            'entities decoded in destination and title' => [
                '[foo](/f&ouml;&ouml; "f&ouml;&ouml;")',
                "<p><a href=\"/föö\" title=\"föö\">foo</a></p>\n",
            ],
            'empty destination' => [
                '[link]()',
                "<p><a href=\"\">link</a></p>\n",
            ],
            'title on the next line' => [
                "[link](   /uri\n  \"title\"  )",
                "<p><a href=\"/uri\" title=\"title\">link</a></p>\n",
            ],
            'code span wins over link' => [
                '[foo`](/uri)`',
                "<p>[foo<code>](/uri)</code></p>\n",
            ],
            'image alt is plain text' => [
                '![*foo* bar](/i.png)',
                "<p><img src=\"/i.png\" alt=\"foo bar\" /></p>\n",
            ],
            'full reference with nested brackets' => [
                "[link [foo [bar]]][ref]\n\n[ref]: /uri",
                "<p><a href=\"/uri\">link [foo [bar]]</a></p>\n",
            ],
            'unicode case folding of labels' => [
                "[ẞ]\n\n[SS]: /url",
                "<p><a href=\"/url\">ẞ</a></p>\n",
            ],
            'shortcut reference followed by parentheses' => [
                "[foo](not a link)\n\n[foo]: /url1",
                "<p><a href=\"/url1\">foo</a>(not a link)</p>\n",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testLink(string $markdown, string $expected): void
    {
        $this->assertSame($expected, (new MarkdownParser())->parse($markdown));
    }

    /** @return array<string, array{string}> */
    public static function encodedSchemes(): array
    {
        return [
            'entity-encoded letter'  => ['[x](java&#115;cript:alert(1))'],
            'entity-encoded colon'   => ['[x](javascript&#58;alert(1))'],
            'entity-encoded newline' => ['[x](java&#x0A;script:alert(1))'],
            'entity-encoded space'   => ['[x](&#32;javascript:alert(1))'],
            'reference definition'   => ["[x]\n\n[x]: javascript:alert(1)"],
        ];
    }

    /** Decoding entities must not let a script scheme through. */
    #[DataProvider('encodedSchemes')]
    public function testDecodedScriptSchemeIsRejected(string $markdown): void
    {
        $this->assertStringNotContainsString('<a ', (new MarkdownParser())->parse($markdown));
    }

    public function testPathologicalBracketsStayLinear(): void
    {
        $start = microtime(true);
        (new MarkdownParser())->parse("[a]: /u\n\n" . str_repeat('[', 200_000));
        (new MarkdownParser())->parse(str_repeat('![a](b) ', 25_000));
        $this->assertLessThan(5.0, microtime(true) - $start);
    }
}
