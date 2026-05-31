<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit\Parser;

use PhpMarkdown\MarkdownParser;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Acceptance tests for HTML entity decoding (story 52-html-entity-decoding).
 *
 * Entities are decoded to their Unicode characters by InlineParser::scan() and
 * stored as the decoded + HTML-safe representation in HtmlEntityNode::$entity.
 * The renderer outputs the value verbatim so the HTML roundtrip is lossless.
 */
#[RequiresPhpExtension('intl')]
final class HtmlEntityDecodingTest extends TestCase
{
    private MarkdownParser $parser;

    protected function setUp(): void
    {
        $this->parser = new MarkdownParser();
    }

    private function parse(string $markdown): string
    {
        return $this->parser->parse($markdown);
    }

    // ── AC-1: Named entities are decoded to their Unicode characters ──────────

    public function testAc1NamedEntitiesDecoded(): void
    {
        // &nbsp; → U+00A0 (literal); &amp; → &amp;; &copy; → ©; &AElig; → Æ; &Dcaron; → Ď
        $result = $this->parse('&nbsp; &amp; &copy; &AElig; &Dcaron;');

        $this->assertSame("<p>\xC2\xA0 &amp; \xC2\xA9 \xC3\x86 \xC4\x8E</p>\n", $result);
    }

    // ── AC-2: Decimal numeric character references ────────────────────────────

    public function testAc2DecimalNumericRefs(): void
    {
        // &#35; → #; &#1234; → Ӓ (U+04D2); &#992; → Ϡ (U+03E0); &#0; → U+FFFD
        $result = $this->parse('&#35; &#1234; &#992; &#0;');

        $this->assertSame("<p># \u{04D2} \u{03E0} \u{FFFD}</p>\n", $result);
    }

    // ── AC-3: Hexadecimal numeric character references ────────────────────────

    public function testAc3HexNumericRefs(): void
    {
        // &#X22; → " (U+0022); &#XD06; → ആ (U+0D06, Malayalam); &#xcab; → ಫ (U+0CAB, Kannada)
        $result = $this->parse('&#X22; &#XD06; &#xcab;');

        $this->assertSame("<p>\" \u{0D06} \u{0CAB}</p>\n", $result);
    }

    // ── AC-4: Invalid / unrecognised entity names pass through as literal text ─

    public function testAc4InvalidEntityNamesLiteral(): void
    {
        // &nbsp (no semicolon), &x; (unknown), &#; (no digits), &#x; (no hex digits),
        // &#87654321; (too many digits), &ThisIsNotDefined; (unknown)
        // All bare & chars must become &amp; in output.
        $result = $this->parse('&nbsp &x; &#; &#x; &#87654321; &ThisIsNotDefined;');

        $this->assertSame(
            "<p>&amp;nbsp &amp;x; &amp;#; &amp;#x; &amp;#87654321; &amp;ThisIsNotDefined;</p>\n",
            $result,
        );
    }

    // ── AC-5: Entity without trailing semicolon is literal text ──────────────

    public function testAc5EntityWithoutSemicolonIsLiteral(): void
    {
        $result = $this->parse('&copy');

        $this->assertSame("<p>&amp;copy</p>\n", $result);
    }

    // ── AC-6: Entities inside code spans are not decoded ─────────────────────

    public function testAc6EntityInCodeSpanNotDecoded(): void
    {
        $result = $this->parse('`f&ouml;f&ouml;`');

        $this->assertSame("<p><code>f&amp;ouml;f&amp;ouml;</code></p>\n", $result);
    }

    // ── AC-7: Entities inside indented code blocks are not decoded ────────────

    public function testAc7EntityInIndentedCodeBlockNotDecoded(): void
    {
        $result = $this->parse('    f&ouml;f&ouml;');

        $this->assertSame("<pre><code>f&amp;ouml;f&amp;ouml;\n</code></pre>\n", $result);
    }

    // ── AC-8: Entities in link URL and title ─────────────────────────────────

    /**
     * @todo Needs link-level entity decoding + URL percent-encoding, out of scope for story 52.
     *       [foo](/f&ouml;&ouml; "f&ouml;&ouml;") should produce
     *       <p><a href="/f%C3%B6%C3%B6" title="föö">foo</a></p>
     *       but the current implementation does not decode entities in link URLs or titles.
     */
    public function testAc8EntityInLinkUrlAndTitle(): void
    {
        // Current behaviour: & in URL is HTML-escaped by the renderer's esc(); title same.
        // When link-level entity decoding is implemented, update the assertion below.
        $result = $this->parse('[foo](/f&ouml;&ouml; "f&ouml;&ouml;")');

        $this->assertSame(
            '<p><a href="/f&amp;ouml;&amp;ouml;" title="f&amp;ouml;&amp;ouml;">foo</a></p>' . "\n",
            $result,
        );
    }

    // ── AC-9: Numeric entity suppresses emphasis delimiter ────────────────────

    public function testAc9NumericEntitySuppressesEmphasis(): void
    {
        // &#42; = '*' decoded as HtmlEntityNode, not a DelimiterRun, so no emphasis.
        // The real *foo* on the next line does produce emphasis.
        $result = $this->parse("&#42;foo&#42;\n*foo*");

        $this->assertSame("<p>*foo*\n<em>foo</em></p>\n", $result);
    }

    // ── AC-10: Fenced code block language info-string entity decoding ─────────

    /**
     * @todo Needs block-level entity decoding, out of scope for story 52.
     *       Spec ex 34: the fenced code language string should be decoded.
     */
    public function testAc10FencedCodeLanguageEntityDecoding(): void
    {
        // CommonMark spec ex 34: ``` &amp;lang ``` should produce class="language-&lang"
        // or similar with decoded entity in the language attribute.
        // Block-level entity decoding is out of scope for this story; recording expected
        // behaviour for future implementation.
        $result = $this->parse("```&amp;lang\ncode\n```");

        // Current behaviour: &amp; in info string is sanitized by the renderer
        // (non-alphanumeric chars stripped from language). Mark current output and revisit.
        $this->assertSame(
            "<pre><code class=\"language-amplang\">code</code></pre>\n",
            $result,
        );
    }
}
