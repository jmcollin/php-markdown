<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Integration;

use PhpMarkdown\Exception\ParseException;
use PhpMarkdown\MarkdownParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

// MarkdownParser::guard() always calls \Normalizer::normalize — all tests require ext-intl.
#[RequiresPhpExtension('intl')]
final class MarkdownParserTest extends TestCase
{
    private MarkdownParser $parser;

    protected function setUp(): void
    {
        $this->parser = new MarkdownParser();
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    #[DataProvider('fixtureProvider')]
    public function testFixtureOutput(string $markdown, string $expectedHtml): void
    {
        $this->assertSame($expectedHtml, $this->parser->parse($markdown));
    }

    /** @return array<string, array{string, string}> */
    public static function fixtureProvider(): array
    {
        $dir = __DIR__ . '/fixtures';
        $fixtures = [];

        foreach (glob($dir . '/*.md') as $mdFile) {
            $name = basename($mdFile, '.md');
            $htmlFile = $dir . '/' . $name . '.html';

            if (file_exists($htmlFile)) {
                $fixtures[$name] = [
                    file_get_contents($mdFile),
                    file_get_contents($htmlFile),
                ];
            }
        }

        return $fixtures;
    }

    // ── General ───────────────────────────────────────────────────────────────

    public function testEmptyInputReturnsEmptyString(): void
    {
        $this->assertSame('', $this->parser->parse(''));
    }

    public function testFullDocumentPipeline(): void
    {
        $md = "# Title\n\nParagraph with **bold**.\n\n- item\n\n> quote\n\n---";
        $html = $this->parser->parse($md);

        $this->assertStringContainsString('<h1>Title</h1>', $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertMatchesRegularExpression('/<ul>\s*<li>item<\/li>\s*<\/ul>/', $html);
        $this->assertStringContainsString('<blockquote>', $html);
        $this->assertStringContainsString('<hr>', $html);
    }

    // ── Guard: input validation ───────────────────────────────────────────────

    public function testInvalidUtf8ThrowsParseException(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Input must be valid UTF-8.');

        $this->parser->parse("\xFF\xFE");
    }

    public function testParseWithMetaThrowsOnInvalidUtf8(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Input must be valid UTF-8.');

        $this->parser->parseWithMeta("\xFF\xFE");
    }

    public function testMaxBytesThrowsParseException(): void
    {
        $parser = new MarkdownParser(maxBytes: 10);

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('exceeds maximum allowed size of 10 bytes');

        $parser->parse(str_repeat('a', 11));
    }

    public function testParseWithMetaThrowsOnOversizedInput(): void
    {
        $parser = new MarkdownParser(maxBytes: 10);

        $this->expectException(ParseException::class);
        $parser->parseWithMeta(str_repeat('a', 11));
    }

    public function testMaxBytesIsEnforcedOnByteCountNotCharCount(): void
    {
        // U+00E9 (é) = 2 bytes in UTF-8. 513 chars = 1026 bytes > 1024 limit.
        $parser = new MarkdownParser(maxBytes: 1024);

        $this->expectException(ParseException::class);
        $parser->parse(str_repeat("\u{00E9}", 513));
    }

    public function testMaxBytesAllowsInputAtExactByteLimit(): void
    {
        // 512 chars × 2 bytes = exactly 1024 bytes — must NOT throw.
        $parser = new MarkdownParser(maxBytes: 1024);

        $html = $parser->parse(str_repeat("\u{00E9}", 512));
        $this->assertIsString($html);
    }

    // ── NFC normalisation ─────────────────────────────────────────────────────

    public function testNfcNormalisationConvertsNfdInput(): void
    {
        // NFD: 'e' (U+0065) + combining acute accent (U+0301) — two codepoints
        $nfd = "e\u{0301}";
        // NFC: precomposed é (U+00E9) — one codepoint
        $nfc = "\u{00E9}";

        $html = $this->parser->parse($nfd);

        $this->assertStringContainsString($nfc, $html);
        $this->assertStringNotContainsString($nfd, $html);
    }

    public function testParseWithMetaNormalisesNfdInput(): void
    {
        $nfd = "e\u{0301}";
        $nfc = "\u{00E9}";

        $result = $this->parser->parseWithMeta($nfd);

        $this->assertStringContainsString($nfc, $result['html']);
    }

    public function testAlreadyNfcInputIsUnchanged(): void
    {
        // Precomposed é is already NFC — normalisation must be idempotent.
        $html = $this->parser->parse("caf\u{00E9}");

        $this->assertStringContainsString("caf\u{00E9}", $html);
    }

    public function testParseDoesNotMutateCallerVariable(): void
    {
        // guard() uses pass-by-reference internally, but parse() takes by value:
        // the caller's variable must never be modified.
        $original = "e\u{0301}";
        $snapshot = $original;

        $this->parser->parse($original);

        $this->assertSame($snapshot, $original);
    }

    public function testParseWithMetaDoesNotMutateCallerVariable(): void
    {
        $original = "e\u{0301}";
        $snapshot = $original;

        $this->parser->parseWithMeta($original);

        $this->assertSame($snapshot, $original);
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function nfcNormalisationProvider(): array
    {
        // [input, expectedNfc, assertInputAbsent]
        // assertInputAbsent=true when input != expectedNfc (confirms replacement occurred).
        return [
            // Angstrom sign (U+212B) → Å (U+00C5)
            'angstrom-sign'              => ["\u{212B}", "\u{00C5}", true],
            // NFD Korean Jamo syllable → precomposed 한 (U+D55C)
            'korean-nfd-syllable'        => ["\u{1112}\u{1161}\u{11AB}", "\u{D55C}", true],
            // u + combining diaeresis (U+0308) → ü (U+00FC)
            'u-with-combining-diaeresis' => ["u\u{0308}", "\u{00FC}", true],
            // Already NFC — must be returned unchanged
            'already-nfc-cafe'           => ["caf\u{00E9}", "caf\u{00E9}", false],
            // Plain ASCII — Normalizer must not alter it
            'plain-ascii'                => ['Hello', 'Hello', false],
        ];
    }

    #[DataProvider('nfcNormalisationProvider')]
    public function testNfcNormalisationRoundtrip(string $input, string $expectedNfc, bool $assertInputAbsent): void
    {
        // Wrap in a code span so the renderer emits the content verbatim.
        $html = $this->parser->parse('`' . $input . '`');

        $this->assertStringContainsString($expectedNfc, $html);
        if ($assertInputAbsent) {
            $this->assertStringNotContainsString($input, $html);
        }
    }

    public function testParseWithMetaNormalisesNfdInputInFrontMatter(): void
    {
        // guard() runs before frontMatter->extract(), so meta values must also be NFC.
        $nfd = "e\u{0301}";
        $nfc = "\u{00E9}";
        $md  = "---\ntitle: caf{$nfd}\n---\nBody";

        $result = $this->parser->parseWithMeta($md);

        $this->assertSame("caf{$nfc}", $result['meta']['title']);
    }
}
