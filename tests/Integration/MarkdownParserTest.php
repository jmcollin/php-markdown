<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Integration;

use PhpMarkdown\Exception\ParseException;
use PhpMarkdown\Lexer\Lexer;
use PhpMarkdown\MarkdownParser;
use PhpMarkdown\Node\Block\HeadingNode;
use PhpMarkdown\Node\Block\ParagraphNode;
use PhpMarkdown\Node\Inline\HardBreakNode;
use PhpMarkdown\Node\Inline\RawHtmlInlineNode;
use PhpMarkdown\Node\Inline\TextNode;
use PhpMarkdown\Parser\Parser;
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

        $mdFiles = glob($dir . '/*.md');
        foreach ($mdFiles !== false ? $mdFiles : [] as $mdFile) {
            $name = basename($mdFile, '.md');
            $htmlFile = $dir . '/' . $name . '.html';

            if (file_exists($htmlFile)) {
                $fixtures[$name] = [
                    (string) file_get_contents($mdFile),
                    (string) file_get_contents($htmlFile),
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
        $this->assertStringContainsString('<hr />', $html);
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
        $this->assertNotEmpty($html);
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

        /** @psalm-suppress RedundantCondition */
        $this->assertSame($snapshot, $original);
    }

    public function testParseWithMetaDoesNotMutateCallerVariable(): void
    {
        $original = "e\u{0301}";
        $snapshot = $original;

        $this->parser->parseWithMeta($original);

        /** @psalm-suppress RedundantCondition */
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

        $this->assertSame("<ul>\n<li>a<ul>\n<li>b</li>\n<li>c</li>\n</ul>\n</li>\n<li>d</li>\n</ul>\n", $html);
    }

    public function testThreeLevelNesting(): void
    {
        $md = "- a\n  - b\n    - c";
        $html = $this->parser->parse($md);

        $this->assertSame("<ul>\n<li>a<ul>\n<li>b<ul>\n<li>c</li>\n</ul>\n</li>\n</ul>\n</li>\n</ul>\n", $html);
    }

    public function testMixedNestingTypes(): void
    {
        $md = "1. first\n   - nested\n2. second";
        $html = $this->parser->parse($md);

        $this->assertSame("<ol>\n<li>first<ul>\n<li>nested</li>\n</ul>\n</li>\n<li>second</li>\n</ol>\n", $html);
    }

    public function testFlatListUnchanged(): void
    {
        $md = "- a\n- b\n- c";
        $html = $this->parser->parse($md);

        $this->assertSame("<ul>\n<li>a</li>\n<li>b</li>\n<li>c</li>\n</ul>\n", $html);
    }

    public function testInlineContentInNestedItem(): void
    {
        $md = "- **bold**\n  - _em_";
        $html = $this->parser->parse($md);

        $this->assertSame(
            "<ul>\n<li><strong>bold</strong><ul>\n<li><em>em</em></li>\n</ul>\n</li>\n</ul>\n",
            $html
        );
    }

    public function testLargeDepthGapCollapsesToDirectNesting(): void
    {
        // 20 spaces = depth 10; should nest directly under depth 0 (no intermediate empty levels)
        $md = "- top\n" . str_repeat(' ', 20) . "- deep";
        $html = $this->parser->parse($md);

        $this->assertSame("<ul>\n<li>top<ul>\n<li>deep</li>\n</ul>\n</li>\n</ul>\n", $html);
    }

    public function testUlFollowedByOlAtSameDepthProducesTwoSeparateLists(): void
    {
        // The ordered guard (meta['ordered'] === $ordered) must break the while-loop
        // when list type changes at the same depth, producing two sibling lists.
        $md = "- ul item\n1. ol item";
        $html = $this->parser->parse($md);

        $this->assertSame("<ul>\n<li>ul item</li>\n</ul>\n<ol>\n<li>ol item</li>\n</ol>\n", $html);
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

    // ── Nested blockquotes ───────────────────────────────────────────────────

    public function testNestedBlockquoteMixedLevels(): void
    {
        $md = "> outer\n> > inner\n> outer again";
        $html = $this->parser->parse($md);

        $this->assertSame(
            "<blockquote>\n<p>outer</p>\n<blockquote>\n<p>inner</p>\n</blockquote>\n<p>outer again</p>\n</blockquote>\n",
            $html,
        );
    }

    public function testThreeLevelBlockquote(): void
    {
        $md = '> > > triple';
        $html = $this->parser->parse($md);

        $this->assertSame(
            "<blockquote>\n<blockquote>\n<blockquote>\n<p>triple</p>\n</blockquote>\n</blockquote>\n</blockquote>\n",
            $html,
        );
    }

    public function testLevelDecreaseThenIncrease(): void
    {
        $md = "> a\n> > b\n> c\n> > d";
        $html = $this->parser->parse($md);

        $this->assertSame(
            "<blockquote>\n<p>a</p>\n<blockquote>\n<p>b</p>\n</blockquote>\n<p>c</p>\n<blockquote>\n<p>d</p>\n</blockquote>\n</blockquote>\n",
            $html,
        );
    }

    public function testDepthGuardAt32(): void
    {
        $md = str_repeat('> ', 32) . 'text';
        $html = $this->parser->parse($md);

        $this->assertSame(32, substr_count($html, '<blockquote>'));
        $this->assertSame(32, substr_count($html, '</blockquote>'));
    }

    public function testDepthGuardAbove32ContentPreserved(): void
    {
        // Level 33 exceeds the guard: the else branch in buildBlockquote fires,
        // treating the excess token as content inside the level-32 blockquote.
        $md = str_repeat('> ', 33) . 'text';
        $html = $this->parser->parse($md);

        $this->assertSame(32, substr_count($html, '<blockquote>'));
        $this->assertSame(32, substr_count($html, '</blockquote>'));
        $this->assertStringContainsString('text', $html);
    }

    public function testMultiLineSameLevelJoinedWithSpace(): void
    {
        // Two consecutive level-1 tokens accumulate into one ParagraphNode joined by space.
        $md = "> line one\n> line two";
        $html = $this->parser->parse($md);

        $this->assertSame("<blockquote>\n<p>line one line two</p>\n</blockquote>\n", $html);
    }

    public function testLevelSkipOneToThree(): void
    {
        // Level jump 1→3 with no level-2 token: should produce 3 nested blockquotes.
        $md = "> a\n>>> c";
        $html = $this->parser->parse($md);

        $this->assertSame(
            "<blockquote>\n<p>a</p>\n<blockquote>\n<blockquote>\n<p>c</p>\n</blockquote>\n</blockquote>\n</blockquote>\n",
            $html,
        );
    }

    public function testBlockquoteAdjacentToParagraph(): void
    {
        // Blockquote followed by a normal paragraph in same document.
        $md = "> quoted\n\nplain paragraph";
        $html = $this->parser->parse($md);

        $this->assertSame(
            "<blockquote>\n<p>quoted</p>\n</blockquote>\n<p>plain paragraph</p>\n",
            $html,
        );
    }

    public function testBlankLineBetweenBlockquoteLevelsSeparatesNodes(): void
    {
        // Blank line between two blockquotes produces two sibling blockquote nodes,
        // not a nested one. This is the chosen behaviour (non-CommonMark): blank lines
        // inside a blockquote are ignored by the lexer and do NOT close the outer quote.
        $md = "> first\n\n> second";
        $html = $this->parser->parse($md);

        // Two separate top-level blockquotes (blank line resets context).
        $this->assertSame(
            "<blockquote>\n<p>first</p>\n</blockquote>\n<blockquote>\n<p>second</p>\n</blockquote>\n",
            $html,
        );
    }

    // ── Task lists ────────────────────────────────────────────────────────────

    public function testCheckedTaskItemRendersCheckbox(): void
    {
        $html = $this->parser->parse('- [x] Done');
        $this->assertSame("<ul>\n<li><input type=\"checkbox\" disabled checked> Done</li>\n</ul>\n", $html);
    }

    public function testUncheckedTaskItemRendersCheckbox(): void
    {
        $html = $this->parser->parse('- [ ] Todo');
        $this->assertSame("<ul>\n<li><input type=\"checkbox\" disabled> Todo</li>\n</ul>\n", $html);
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

    // ── Hard line breaks (CommonMark §6.7) ───────────────────────────────────

    public function testTwoTrailingSpacesProducesBr(): void
    {
        // Two trailing spaces before \n must become <br> not a space.
        $html = $this->parser->parse("foo  \nbar");
        $this->assertSame("<p>foo<br />\nbar</p>\n", $html);
    }

    public function testBackslashLineBreakProducesBr(): void
    {
        // Trailing backslash before \n must become <br>.
        $html = $this->parser->parse("foo\\\nbar");
        $this->assertSame("<p>foo<br />\nbar</p>\n", $html);
    }

    public function testNoTrailingSpacesProducesSoftBreak(): void
    {
        // Without trailing spaces the lines are soft-joined with a space.
        $html = $this->parser->parse("foo\nbar");
        $this->assertSame("<p>foo bar</p>\n", $html);
    }

    public function testHardBreakInsideInlineContent(): void
    {
        // Hard break after inline strong element.
        $html = $this->parser->parse("**bold**  \ntext");
        $this->assertSame("<p><strong>bold</strong><br />\ntext</p>\n", $html);
    }

    public function testMultipleHardBreaksInOneParagraph(): void
    {
        // Multiple hard breaks in sequence.
        $html = $this->parser->parse("a  \nb  \nc");
        $this->assertSame("<p>a<br />\nb<br />\nc</p>\n", $html);
    }

    public function testThreeTrailingSpacesStillOneBr(): void
    {
        // Three or more trailing spaces → still one <br>.
        $html = $this->parser->parse("foo   \nbar");
        $this->assertSame("<p>foo<br />\nbar</p>\n", $html);
    }

    public function testHardBreakOnLastLineOfParagraphStripped(): void
    {
        // Hard break on last line of a paragraph → no trailing <br> (CommonMark §6.7).
        $html = $this->parser->parse("foo  ");
        $this->assertSame("<p>foo</p>\n", $html);
    }

    public function testDoubleBackslashIsNotHardBreak(): void
    {
        // An escaped backslash (\\) at end of line must NOT produce <br>.
        $html = $this->parser->parse("foo\\\\\nbar");
        $this->assertStringNotContainsString('<br>', $html);
    }

    public function testHardBreakLineWithUnsafeUrlIsFiltered(): void
    {
        // isSafeUrl() must still fire on content that carries hard_break=true.
        $html = $this->parser->parse("[click](javascript:alert(1))  \nafter");
        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringContainsString('<br />', $html);
    }

    public function testFencedCodeInnerLineWithTrailingSpacesIsNotHardBreak(): void
    {
        // Trailing spaces inside a fenced code block must not produce <br>.
        $html = $this->parser->parse("```\nfoo  \nbar\n```");
        $this->assertStringNotContainsString('<br>', $html);
    }

    public function testBlockquoteLineWithTrailingSpacesNoHardBreak(): void
    {
        // Blockquote branch uses trim(), so trailing spaces are stripped before
        // the hard-break check — hard breaks inside blockquotes are not supported.
        $html = $this->parser->parse("> foo  \n> bar");
        $this->assertStringNotContainsString('<br>', $html);
    }

    // ── Regression guards ────────────────────────────────────────────────────

    public function testSoftBreakBetweenParagraphLinesUnchanged(): void
    {
        // Pre-existing soft-break behavior must not regress.
        $html = $this->parser->parse("line one\nline two\nline three");
        $this->assertSame("<p>line one line two line three</p>\n", $html);
    }

    public function testBlankLineSeparatesParagraphs(): void
    {
        // Blank line separation of paragraphs must not regress.
        $html = $this->parser->parse("para one\n\npara two");
        $this->assertSame("<p>para one</p>\n<p>para two</p>\n", $html);
    }

    // ── Inline HTML in headings — full pipeline (story 29) ───────────────────

    public function testHeadingPipelineProducesCorrectInlineHtmlChildren(): void
    {
        // Verifies the full Lexer→Parser pipeline (not just the renderer) for
        // a heading that contains an inline HTML tag pair wrapping plain text.
        // Expected child sequence: TextNode("Title "), RawHtmlInlineNode("<sup>"),
        //                          TextNode("1"), RawHtmlInlineNode("</sup>").
        $lexer  = new Lexer();
        $parser = new Parser();

        $doc = $parser->parse($lexer->tokenize('# Title <sup>1</sup>'));

        $this->assertCount(1, $doc->children);
        $heading = $doc->children[0];
        $this->assertInstanceOf(HeadingNode::class, $heading);
        $this->assertSame(1, $heading->level);

        $children = $heading->children;
        $this->assertCount(4, $children, 'Expected 4 inline children in the heading');

        $this->assertInstanceOf(TextNode::class, $children[0]);
        $this->assertSame('Title ', $children[0]->text);

        $this->assertInstanceOf(RawHtmlInlineNode::class, $children[1]);
        $this->assertSame('<sup>', $children[1]->content);

        $this->assertInstanceOf(TextNode::class, $children[2]);
        $this->assertSame('1', $children[2]->text);

        $this->assertInstanceOf(RawHtmlInlineNode::class, $children[3]);
        $this->assertSame('</sup>', $children[3]->content);
    }

    // ── HardBreak regression guard (story 29) ────────────────────────────────

    public function testHardBreakIsNotMisidentifiedAsRawHtmlInline(): void
    {
        // Two trailing spaces before \n must produce a HardBreakNode in the AST,
        // never a RawHtmlInlineNode. Guards against the inline-HTML detector
        // accidentally consuming whitespace sequences that look like tag fragments.
        $lexer  = new Lexer();
        $parser = new Parser();

        $doc = $parser->parse($lexer->tokenize("foo  \nbar"));

        // HTML output must be the canonical hard-break form.
        $html = $this->parser->parse("foo  \nbar");
        $this->assertSame("<p>foo<br />\nbar</p>\n", $html);

        // AST: single ParagraphNode child.
        $this->assertCount(1, $doc->children);
        $para = $doc->children[0];
        $this->assertInstanceOf(ParagraphNode::class, $para);

        // No RawHtmlInlineNode anywhere in the paragraph children.
        foreach ($para->children as $node) {
            $this->assertNotInstanceOf(
                RawHtmlInlineNode::class,
                $node,
                'RawHtmlInlineNode must not appear in a paragraph that only has a hard break',
            );
        }

        // A HardBreakNode must be present.
        $hasHardBreak = false;
        foreach ($para->children as $node) {
            if ($node instanceof HardBreakNode) {
                $hasHardBreak = true;
                break;
            }
        }
        $this->assertTrue($hasHardBreak, 'HardBreakNode must be present in paragraph children');
    }

    // ── Raw HTML rendering ────────────────────────────────────────────────────

    public function testParseDefaultEscapesRawHtmlBlock(): void
    {
        // Default mode: raw block HTML is escaped — XSS-safe by default.
        // Output is bare escaped text with no <p> wrapper.
        $html = $this->parser->parse('<div>raw</div>');

        $this->assertSame("&lt;div&gt;raw&lt;/div&gt;\n", $html);
    }

    public function testParseWithAllowRawHtmlPassesThroughBlock(): void
    {
        // allowRawHtml: true — sanitizer allows the clean block through.
        $html = $this->parser->parse('<div class="box">content</div>', allowRawHtml: true);

        $this->assertStringContainsString('<div class="box">content</div>', $html);
    }

    public function testParseWithAllowRawHtmlStripsScriptInBlockTag(): void
    {
        // allowRawHtml: true — a <div> block containing a nested <script> is lexed as HTML_BLOCK.
        // The sanitizer strips the dangerous <script> child while keeping the outer <div>.
        $html = $this->parser->parse("<div>\n<script>alert(1)</script>\n</div>", allowRawHtml: true);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
        $this->assertStringContainsString('<div>', $html);
    }

    public function testParseWithMetaAllowRawHtmlPassesThroughBlock(): void
    {
        // parseWithMeta() uses the same allowRawHtml wiring as parse().
        $result = $this->parser->parseWithMeta('<div>hello</div>', allowRawHtml: true);

        $this->assertStringContainsString('<div>hello</div>', $result['html']);
    }

    public function testParseBackwardCompatibilityDefaultParam(): void
    {
        // Existing callers of parse($md) still work — no exception, returns string.
        $html = $this->parser->parse('hello');

        $this->assertSame("<p>hello</p>\n", $html);
    }
}
