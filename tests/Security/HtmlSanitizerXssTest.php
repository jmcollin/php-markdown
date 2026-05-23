<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Security;

use PhpMarkdown\Sanitizer\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

final class HtmlSanitizerXssTest extends TestCase
{
    private HtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new HtmlSanitizer();
    }

    // -------------------------------------------------------------------------
    // Test 1 — CSS style attribute with javascript: URL stripped
    // -------------------------------------------------------------------------

    public function testCssStyleAttributeWithJavascriptUrlStripped(): void
    {
        $this->assertSame(
            '<p>text</p>',
            $this->sanitizer->sanitize('<p style="background:url(javascript:alert(1))">text</p>'),
        );
    }

    // -------------------------------------------------------------------------
    // Test 2 — SVG tag (and subtree) removed entirely
    // -------------------------------------------------------------------------

    public function testSvgTagRemovedEntirely(): void
    {
        $this->assertSame(
            '',
            $this->sanitizer->sanitize('<svg onload="alert(1)">'),
        );
    }

    // -------------------------------------------------------------------------
    // Test 3 — Quote-breakout via entity-encoded attribute value neutralized
    // -------------------------------------------------------------------------

    public function testQuoteBreakoutViaEntityEncodedAttributeValueNeutralized(): void
    {
        $result = $this->sanitizer->sanitize('<img alt="&quot; onerror=&quot;evil()">');

        // DOMDocument decodes &quot; as literal " inside the attribute value.
        // The entire injection becomes the value of alt, not a separate attribute.
        // Confirm the img tag and alt attribute are present.
        $this->assertStringContainsString('<img', $result);
        $this->assertStringContainsString('alt=', $result);
        // Re-parse the sanitized output and assert no onerror DOM attribute exists.
        // This distinguishes "onerror inside an alt value" from "onerror as an attribute".
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<html><body>' . $result . '</body></html>');
        libxml_clear_errors();
        $imgs = $dom->getElementsByTagName('img');
        $img  = $imgs->item(0);
        $this->assertNotNull($img, 'img element must be present in sanitized output');
        $this->assertFalse($img->hasAttribute('onerror'), 'onerror must not be a DOM attribute');
    }

    // -------------------------------------------------------------------------
    // Test 4 — Duplicate class attribute mutation XSS rejected
    // -------------------------------------------------------------------------

    public function testDuplicateClassAttributeMutationXssRejected(): void
    {
        // Mutation XSS vector: onmouseover=evil() is embedded inside the value of the
        // second class attribute, not as a standalone attribute. DOMDocument coalesces
        // duplicate class attrs (keeps first class="x"), so onmouseover never becomes
        // a DOM attribute and must not appear in the sanitized output.
        $result = $this->sanitizer->sanitize('<div class="x" class="y onmouseover=evil()">text</div>');

        $this->assertStringContainsString('<div', $result);
        $this->assertStringContainsString('class="x"', $result);
        $this->assertStringNotContainsString('onmouseover', $result);
        $this->assertStringNotContainsString('class="y', $result);
    }

    // -------------------------------------------------------------------------
    // Test 5 — link tag removed entirely
    // -------------------------------------------------------------------------

    public function testLinkTagRemovedEntirely(): void
    {
        $this->assertSame(
            '',
            $this->sanitizer->sanitize('<link rel="stylesheet" href="evil.css">'),
        );
    }

    // -------------------------------------------------------------------------
    // Test 6 — form with protocol-relative action removed entirely
    // -------------------------------------------------------------------------

    public function testFormWithProtocolRelativeActionRemovedEntirely(): void
    {
        $this->assertSame(
            '',
            $this->sanitizer->sanitize('<form action="//evil.com/steal"><button>submit</button></form>'),
        );
    }

    // -------------------------------------------------------------------------
    // Test 7 — Null byte in attribute value stripped
    // -------------------------------------------------------------------------

    public function testNullByteInAttributeValueStripped(): void
    {
        $input  = '<img src="https://' . "\x00" . 'evil.com/x.png" alt="x">';
        $result = $this->sanitizer->sanitize($input);

        // isSafeUrl() rejects URLs containing control characters (\x00).
        // The src attribute must be stripped; the img element itself must be kept.
        // Note: DOMDocument truncates the attribute list at the null byte during parsing,
        // so alt is also absent from the output — this is acceptable: the security
        // property (no dangerous src URL in output) is what matters here.
        $this->assertStringNotContainsString('src=', $result);
        $this->assertStringContainsString('<img', $result);
    }

    // -------------------------------------------------------------------------
    // Test 8 — Very long safe attribute value does not cause DoS
    // -------------------------------------------------------------------------

    public function testVeryLongSafeAttributeValueDoesNotCauseDoS(): void
    {
        // 2000ms threshold (CI-safe; actual runtime < 5ms)
        $input = '<p title="' . str_repeat('A', 10000) . '">text</p>';

        $start   = hrtime(true);
        $result  = $this->sanitizer->sanitize($input);
        $elapsed = (hrtime(true) - $start) / 1_000_000; // ms

        $this->assertIsString($result);
        $this->assertStringContainsString('text', $result);
        $this->assertStringContainsString('title=', $result);
        $this->assertLessThan(2000, $elapsed, 'Sanitizer must complete within 2 seconds on long safe attrs');
    }

    // -------------------------------------------------------------------------
    // Test 9 — Deeply nested tags do not cause stack overflow
    // -------------------------------------------------------------------------

    public function testDeeplyNestedTagsDoNotCauseStackOverflow(): void
    {
        $html = str_repeat('<div>', 100) . 'text' . str_repeat('</div>', 100);

        $result = $this->sanitizer->sanitize($html);

        $this->assertIsString($result);
        $this->assertStringNotContainsString('Fatal', $result);
        $this->assertStringContainsString('<div>', $result);
        $this->assertStringContainsString('text', $result);
    }
}
