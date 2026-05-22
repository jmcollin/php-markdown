<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit;

use PhpMarkdown\Sanitizer\HtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HtmlSanitizerTest extends TestCase
{
    private HtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new HtmlSanitizer();
    }

    // -------------------------------------------------------------------------
    // Safe tag passthrough
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{input: string, expected: string}>
     */
    public static function safeTagPassthroughProvider(): array
    {
        return [
            'em tag preserved' => [
                'input'    => '<em>hello</em>',
                'expected' => '<em>hello</em>',
            ],
            'strong tag preserved' => [
                'input'    => '<strong>world</strong>',
                'expected' => '<strong>world</strong>',
            ],
            'p tag preserved' => [
                'input'    => '<p>paragraph</p>',
                'expected' => '<p>paragraph</p>',
            ],
            'a tag with safe href preserved' => [
                'input'    => '<a href="https://example.com">link</a>',
                'expected' => '<a href="https://example.com">link</a>',
            ],
            'img tag with safe src preserved' => [
                'input'    => '<img src="https://example.com/img.png" alt="alt">',
                'expected' => '<img src="https://example.com/img.png" alt="alt">',
            ],
        ];
    }

    #[DataProvider('safeTagPassthroughProvider')]
    public function testSafeTagPassthrough(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->sanitizer->sanitize($input));
    }

    // -------------------------------------------------------------------------
    // Forbidden tag stripped (tag + content)
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{input: string, expected: string}>
     */
    public static function forbiddenTagStrippedProvider(): array
    {
        return [
            'script removed entirely' => [
                'input'    => '<script>alert(1)</script>',
                'expected' => '',
            ],
            'style removed entirely' => [
                'input'    => '<style>body{color:red}</style>',
                'expected' => '',
            ],
            'iframe removed entirely' => [
                'input'    => '<iframe src="https://evil.com"></iframe>',
                'expected' => '',
            ],
            'form removed entirely' => [
                'input'    => '<form action="/submit"><input type="text"></form>',
                'expected' => '',
            ],
            'input removed entirely' => [
                'input'    => '<input type="hidden" value="x">',
                'expected' => '',
            ],
            'noscript removed entirely' => [
                'input'    => '<noscript>enable js</noscript>',
                'expected' => '',
            ],
            'template removed entirely' => [
                'input'    => '<template><p>tpl</p></template>',
                'expected' => '',
            ],
            'sibling text preserved when forbidden tag removed' => [
                'input'    => 'before<script>evil()</script>after',
                'expected' => '<p>beforeafter</p>',
            ],
        ];
    }

    #[DataProvider('forbiddenTagStrippedProvider')]
    public function testForbiddenTagStripped(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->sanitizer->sanitize($input));
    }

    // -------------------------------------------------------------------------
    // Dangerous attribute stripped
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{input: string, expected: string}>
     */
    public static function dangerousAttributeStrippedProvider(): array
    {
        return [
            'onclick removed' => [
                'input'    => '<p onclick="evil()">text</p>',
                'expected' => '<p>text</p>',
            ],
            'onmouseover removed' => [
                'input'    => '<span onmouseover="x()">text</span>',
                'expected' => '<span>text</span>',
            ],
            'style attribute removed' => [
                'input'    => '<p style="color:red">text</p>',
                'expected' => '<p>text</p>',
            ],
            'xmlns removed' => [
                'input'    => '<p xmlns="http://evil.com">text</p>',
                'expected' => '<p>text</p>',
            ],
            'xlink:href removed' => [
                'input'    => '<a xlink:href="javascript:alert(1)">text</a>',
                'expected' => '<a>text</a>',
            ],
        ];
    }

    #[DataProvider('dangerousAttributeStrippedProvider')]
    public function testDangerousAttributeStripped(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->sanitizer->sanitize($input));
    }

    // -------------------------------------------------------------------------
    // JavaScript URL rejected
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{input: string, expected: string}>
     */
    public static function javascriptUrlRejectedProvider(): array
    {
        return [
            'javascript href removed, tag kept' => [
                'input'    => '<a href="javascript:alert(1)">click</a>',
                'expected' => '<a>click</a>',
            ],
            'javascript src removed, tag kept' => [
                'input'    => '<img src="javascript:alert(1)" alt="x">',
                'expected' => '<img alt="x">',
            ],
        ];
    }

    #[DataProvider('javascriptUrlRejectedProvider')]
    public function testJavascriptUrlRejected(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->sanitizer->sanitize($input));
    }

    // -------------------------------------------------------------------------
    // Data URI rejected
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{input: string, expected: string}>
     */
    public static function dataUriRejectedProvider(): array
    {
        return [
            'data URI in img src removed' => [
                'input'    => '<img src="data:image/svg+xml,<svg/>" alt="x">',
                'expected' => '<img alt="x">',
            ],
            'data URI in a href removed' => [
                'input'    => '<a href="data:text/html,<h1>pwned</h1>">click</a>',
                'expected' => '<a>click</a>',
            ],
        ];
    }

    #[DataProvider('dataUriRejectedProvider')]
    public function testDataUriRejected(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->sanitizer->sanitize($input));
    }

    // -------------------------------------------------------------------------
    // Encoded XSS rejected
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{input: string, expected: string}>
     */
    public static function encodedXssRejectedProvider(): array
    {
        return [
            'HTML entity encoded javascript href rejected' => [
                'input'    => '<a href="&#106;avascript:alert(1)">x</a>',
                'expected' => '<a>x</a>',
            ],
            'percent encoded javascript href rejected' => [
                'input'    => '<a href="%6Aavascript:alert(1)">x</a>',
                'expected' => '<a>x</a>',
            ],
            'mixed encoding javascript href rejected' => [
                'input'    => '<a href="&#x6A;avascript:alert(1)">x</a>',
                'expected' => '<a>x</a>',
            ],
        ];
    }

    #[DataProvider('encodedXssRejectedProvider')]
    public function testEncodedXssRejected(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->sanitizer->sanitize($input));
    }

    // -------------------------------------------------------------------------
    // Safe attributes kept
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{input: string, expected: string}>
     */
    public static function safeAttributesKeptProvider(): array
    {
        return [
            'https href kept' => [
                'input'    => '<a href="https://example.com">link</a>',
                'expected' => '<a href="https://example.com">link</a>',
            ],
            'title attribute kept' => [
                'input'    => '<p title="tip">text</p>',
                'expected' => '<p title="tip">text</p>',
            ],
            'rel attribute kept on a' => [
                'input'    => '<a href="https://x.com" rel="noreferrer">link</a>',
                'expected' => '<a href="https://x.com" rel="noreferrer">link</a>',
            ],
            'class attribute kept' => [
                'input'    => '<p class="note">text</p>',
                'expected' => '<p class="note">text</p>',
            ],
            'id attribute kept' => [
                'input'    => '<p id="main">text</p>',
                'expected' => '<p id="main">text</p>',
            ],
            'lang attribute kept' => [
                'input'    => '<p lang="fr">texte</p>',
                'expected' => '<p lang="fr">texte</p>',
            ],
            'dir attribute kept' => [
                'input'    => '<p dir="rtl">text</p>',
                'expected' => '<p dir="rtl">text</p>',
            ],
            'aria-label kept' => [
                'input'    => '<span aria-label="desc">x</span>',
                'expected' => '<span aria-label="desc">x</span>',
            ],
            'aria-hidden kept' => [
                'input'    => '<span aria-hidden="true">x</span>',
                'expected' => '<span aria-hidden="true">x</span>',
            ],
            'colspan kept on td' => [
                'input'    => '<table><tbody><tr><td colspan="2">cell</td></tr></tbody></table>',
                'expected' => '<table><tbody><tr><td colspan="2">cell</td></tr></tbody></table>',
            ],
            'rowspan kept on th' => [
                'input'    => '<table><tbody><tr><th rowspan="3">head</th></tr></tbody></table>',
                'expected' => '<table><tbody><tr><th rowspan="3">head</th></tr></tbody></table>',
            ],
            'datetime kept on time' => [
                'input'    => '<time datetime="2024-01-01">New Year</time>',
                'expected' => '<time datetime="2024-01-01">New Year</time>',
            ],
            'alt kept on img' => [
                'input'    => '<img src="https://example.com/x.png" alt="desc">',
                'expected' => '<img src="https://example.com/x.png" alt="desc">',
            ],
        ];
    }

    #[DataProvider('safeAttributesKeptProvider')]
    public function testSafeAttributesKept(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->sanitizer->sanitize($input));
    }

    // -------------------------------------------------------------------------
    // Individual tests
    // -------------------------------------------------------------------------

    public function testTargetBlankKept(): void
    {
        $result = $this->sanitizer->sanitize('<a href="https://x.com" target="_blank">link</a>');
        $this->assertStringContainsString('target="_blank"', $result);
        $this->assertStringContainsString('noopener', $result);
    }

    public function testTargetOtherRejected(): void
    {
        $result = $this->sanitizer->sanitize('<a href="https://x.com" target="_self">link</a>');
        $this->assertStringNotContainsString('target', $result);
    }

    public function testTargetBlankAddsNoopenerAdditively(): void
    {
        $result = $this->sanitizer->sanitize('<a href="https://x.com" target="_blank" rel="noreferrer">link</a>');
        $this->assertStringContainsString('noreferrer', $result);
        $this->assertStringContainsString('noopener', $result);
        $this->assertStringContainsString('target="_blank"', $result);
    }

    public function testUnknownTagUnwrapped(): void
    {
        $result = $this->sanitizer->sanitize('<blink>text</blink>');
        $this->assertSame('text', $result);
    }

    public function testUnknownTagWithSafeChildrenUnwrapped(): void
    {
        $result = $this->sanitizer->sanitize('<blink><em>text</em></blink>');
        $this->assertSame('<em>text</em>', $result);
    }

    public function testEmptyInputReturnsEmpty(): void
    {
        $this->assertSame('', $this->sanitizer->sanitize(''));
    }

    public function testNestedForbiddenTagFullyRemoved(): void
    {
        $result = $this->sanitizer->sanitize('<div><script>evil()</script>safe text</div>');
        $this->assertSame('<div>safe text</div>', $result);
    }

    public function testAriaWildcardAllowed(): void
    {
        $result = $this->sanitizer->sanitize('<span aria-label="x" aria-hidden="true">y</span>');
        $this->assertStringContainsString('aria-label="x"', $result);
        $this->assertStringContainsString('aria-hidden="true"', $result);
    }

    public function testAriaWithUppercasePreserved(): void
    {
        $result = $this->sanitizer->sanitize('<span ARIA-LABEL="x">y</span>');
        // The attribute value must be preserved; name may be normalized by DOM
        $this->assertStringContainsString('aria-label', strtolower($result));
        $this->assertStringContainsString('>y</span>', $result);
    }

    public function testOutputHasNoDoctypeOrHtmlWrapper(): void
    {
        $result = $this->sanitizer->sanitize('<p>hello</p>');
        $this->assertStringNotContainsString('<!DOCTYPE', $result);
        $this->assertStringNotContainsString('<html', $result);
        $this->assertStringNotContainsString('<body', $result);
        $this->assertStringNotContainsString('<head', $result);
    }

    public function testHtmlCommentsAreStripped(): void
    {
        $result = $this->sanitizer->sanitize('<p>before<!-- comment -->after</p>');
        $this->assertStringNotContainsString('<!--', $result);
        $this->assertStringNotContainsString('comment', $result);
        $this->assertStringContainsString('beforeafter', $result);
    }

    public function testMailtoHrefAllowed(): void
    {
        $result = $this->sanitizer->sanitize('<a href="mailto:user@example.com">email</a>');
        $this->assertStringContainsString('href="mailto:user@example.com"', $result);
    }

    public function testRelativeUrlAllowed(): void
    {
        $result = $this->sanitizer->sanitize('<a href="/path/to/page">local</a>');
        $this->assertStringContainsString('href="/path/to/page"', $result);
    }
}
