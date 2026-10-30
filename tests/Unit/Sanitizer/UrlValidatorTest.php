<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit\Sanitizer;

use PhpMarkdown\Sanitizer\UrlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlValidatorTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function safeUrls(): array
    {
        return [
            'https'                 => ['https://example.com/a?b=c#d'],
            'http'                  => ['http://example.com'],
            'mailto'                => ['mailto:a@example.com'],
            'relative path'         => ['/docs/page'],
            'fragment'              => ['#section'],
            'empty'                 => [''],
            'inner space'           => ['/my uri'],
            'percent-encoded space' => ['foo%20bar'],
            'uppercase scheme'      => ['HTTPS://example.com'],
            'colon after slash'     => ['/a:b'],
            'colon in query'        => ['?q=a:b'],
            'host:port http'        => ['http://example.com:8080/x'],
        ];
    }

    #[DataProvider('safeUrls')]
    public function testSafe(string $url): void
    {
        $this->assertTrue(UrlValidator::isSafe($url));
    }

    /** @return array<string, array{string}> */
    public static function unsafeUrls(): array
    {
        return [
            'javascript'                 => ['javascript:alert(1)'],
            'mixed case'                 => ['JaVaScRiPt:alert(1)'],
            'leading space'              => [' javascript:alert(1)'],
            'surrounding spaces'         => ['  javascript:alert(1)  '],
            'tab inside scheme'          => ["java\tscript:alert(1)"],
            'newline inside scheme'      => ["java\nscript:alert(1)"],
            'percent-encoded newline'    => ['java%0Ascript:alert(1)'],
            'percent-encoded scheme'     => ['%6Aavascript:alert(1)'],
            'null byte'                  => ["javascript\0:alert(1)"],
            'vbscript'                   => ['vbscript:msgbox(1)'],
            'data'                       => ['data:text/html,<script>alert(1)</script>'],
            'protocol-relative'          => ['//evil.example'],
            'backslash-prefixed'         => ['\\\\evil.example'],
            'other scheme'               => ['ftp://files.example.com'],
            // parse_url() reads "javascript:1" as host "javascript" + port 1 (no scheme);
            // browsers still run it, and "1/alert(1)" is valid JavaScript.
            'host:port lookalike'        => ['javascript:1'],
            'host:port lookalike + path' => ['javascript:1/alert(1)'],
            'host:port lookalike, case'  => ['JavaScript:0/alert(document.domain)'],
            'vbscript host:port'         => ['vbscript:1'],
            'data host:port'             => ['data:1'],
        ];
    }

    #[DataProvider('unsafeUrls')]
    public function testUnsafe(string $url): void
    {
        $this->assertFalse(UrlValidator::isSafe($url));
    }

    public function testScriptSchemeDetectionForAutolinks(): void
    {
        $this->assertTrue(UrlValidator::hasScriptScheme('JavaScript:alert(1)'));
        $this->assertTrue(UrlValidator::hasScriptScheme('data:text/html,x'));
        $this->assertFalse(UrlValidator::hasScriptScheme('ftp://files.example.com'));
        $this->assertFalse(UrlValidator::hasScriptScheme('no-colon'));
    }
}
