<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Security;

use PhpMarkdown\MarkdownParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Script-capable URL schemes must never reach an href/src, in the default (escaped) mode.
 */
final class LinkSchemeBypassTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function payloads(): array
    {
        return [
            'angle link, leading space'      => ['[a](< javascript:alert(1)>)'],
            'angle link, spaces both sides'  => ['[a](<  JaVaScRiPt:alert(1)  >)'],
            'angle image, leading space'     => ['![a](< javascript:alert(1)>)'],
            'autolink javascript'            => ['<javascript:alert(1)>'],
            'autolink vbscript'              => ['<vbscript:msgbox(1)>'],
            'autolink data'                  => ['<data:text/html;base64,PHNjcmlwdD4=>'],
        ];
    }

    #[DataProvider('payloads')]
    public function testScriptSchemeIsNotLinked(string $markdown): void
    {
        $html = strtolower((new MarkdownParser())->parse($markdown));

        $this->assertDoesNotMatchRegularExpression('/(?:href|src)="\s*(?:javascript|vbscript|data):/', $html);
    }

    public function testAngleLinkWithSpacesStillWorks(): void
    {
        $this->assertSame(
            "<p><a href=\"/my uri\">a</a></p>\n",
            (new MarkdownParser())->parse('[a](</my uri>)'),
        );
    }

    public function testCustomSchemeAutolinkStillWorks(): void
    {
        $this->assertSame(
            "<p><a href=\"ftp://files.example.com\">ftp://files.example.com</a></p>\n",
            (new MarkdownParser())->parse('<ftp://files.example.com>'),
        );
    }
}
