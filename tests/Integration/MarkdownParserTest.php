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

    // ── Nested lists ──────────────────────────────────────────────────────────

    public function testSimpleNestedList(): void
    {
        $md = "- a\n  - b\n  - c\n- d";
        $html = $this->parser->parse($md);

        $this->assertSame('<ul><li>a<ul><li>b</li><li>c</li></ul></li><li>d</li></ul>', $html);
    }

    public function testThreeLevelNesting(): void
    {
        $md = "- a\n  - b\n    - c";
        $html = $this->parser->parse($md);

        $this->assertSame('<ul><li>a<ul><li>b<ul><li>c</li></ul></li></ul></li></ul>', $html);
    }

    public function testMixedNestingTypes(): void
    {
        $md = "1. first\n   - nested\n2. second";
        $html = $this->parser->parse($md);

        $this->assertSame('<ol><li>first<ul><li>nested</li></ul></li><li>second</li></ol>', $html);
    }

    public function testFlatListUnchanged(): void
    {
        $md = "- a\n- b\n- c";
        $html = $this->parser->parse($md);

        $this->assertSame('<ul><li>a</li><li>b</li><li>c</li></ul>', $html);
    }

    public function testInlineContentInNestedItem(): void
    {
        $md = "- **bold**\n  - _em_";
        $html = $this->parser->parse($md);

        $this->assertSame(
            '<ul><li><strong>bold</strong><ul><li><em>em</em></li></ul></li></ul>',
            $html
        );
    }

    public function testLargeDepthGapCollapsesToDirectNesting(): void
    {
        // 20 spaces = depth 10; should nest directly under depth 0 (no intermediate empty levels)
        $md = "- top\n" . str_repeat(' ', 20) . "- deep";
        $html = $this->parser->parse($md);

        $this->assertSame('<ul><li>top<ul><li>deep</li></ul></li></ul>', $html);
    }

    public function testUlFollowedByOlAtSameDepthProducesTwoSeparateLists(): void
    {
        // The ordered guard (meta['ordered'] === $ordered) must break the while-loop
        // when list type changes at the same depth, producing two sibling lists.
        $md = "- ul item\n1. ol item";
        $html = $this->parser->parse($md);

        $this->assertSame('<ul><li>ul item</li></ul><ol><li>ol item</li></ol>', $html);
    }

    public function testDepthCapAt32DoesNotCrashAndProducesTwoLists(): void
    {
        // The $depth < 32 guard fires when the PARENT is at depth=32 and a child
        // would go deeper. Build a 32-level chain ending with a depth-33 item.
        // depth = floor(spaces / 2); depth 32 = 64 spaces, depth 33 = 66 spaces.
        $lines   = [];
        $lines[] = '- root'; // depth 0
        for ($d = 1; $d <= 32; $d++) {
            $lines[] = str_repeat(' ', $d * 2) . '- item' . $d; // depth 1..32
        }
        // Add one item beyond the cap: depth 33 = 66 spaces
        $lines[] = str_repeat(' ', 66) . '- overflow';
        $md = implode("\n", $lines);

        // Must not throw regardless of output shape.
        $html = $this->parser->parse($md);

        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('root', $html);
        $this->assertStringContainsString('overflow', $html);
    }

    // ── Task lists ────────────────────────────────────────────────────────────

    public function testCheckedTaskItemRendersCheckbox(): void
    {
        $html = $this->parser->parse('- [x] Done');
        $this->assertSame('<ul><li><input type="checkbox" disabled checked> Done</li></ul>', $html);
    }

    public function testUncheckedTaskItemRendersCheckbox(): void
    {
        $html = $this->parser->parse('- [ ] Todo');
        $this->assertSame('<ul><li><input type="checkbox" disabled> Todo</li></ul>', $html);
    }

    public function testMixedTaskList(): void
    {
        $html = $this->parser->parse("- [x] done\n- [ ] todo\n- plain");
        $this->assertStringContainsString('<input type="checkbox" disabled checked>', $html);
        $this->assertStringContainsString('<input type="checkbox" disabled>', $html);
        $this->assertStringContainsString('<li>plain</li>', $html);
    }

    public function testInlineContentInTaskItem(): void
    {
        $html = $this->parser->parse('- [x] **bold** done');
        $this->assertMatchesRegularExpression(
            '/<li><input type="checkbox" disabled checked> <strong>bold<\/strong>/',
            $html,
        );
    }

    public function testTaskItemWithNoTextAfterMarkerIsPlainItem(): void
    {
        // "- [x] " has no content after the space; trim('[x] ') = '[x]'
        // which doesn't satisfy \s+ in extractTaskChecked → plain item.
        $html = $this->parser->parse("- [x] \n- text");
        $this->assertStringNotContainsString('<input', $html);
        $this->assertStringContainsString('<li>[x]</li>', $html);
    }

    public function testOrderedTaskList(): void
    {
        $html = $this->parser->parse("1. [x] first\n2. [ ] second");
        $this->assertStringContainsString('<ol>', $html);
        $this->assertStringContainsString('<input type="checkbox" disabled checked>', $html);
        $this->assertStringContainsString('<input type="checkbox" disabled>', $html);
    }

    public function testUppercaseXChecked(): void
    {
        $html = $this->parser->parse('- [X] Done');
        $this->assertStringContainsString('<input type="checkbox" disabled checked>', $html);
    }
}
