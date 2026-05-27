<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit;

use PhpMarkdown\Lexer\Lexer;
use PhpMarkdown\Parser\Parser;
use PhpMarkdown\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Full-stack tests for footnote support (GFM extension).
 *
 * Covers AC-1 through AC-14 and EC-1 through EC-7 from story 46-footnotes,
 * plus regression guards for InlineParser, Lexer, and backslash escapes.
 */
final class FootnoteTest extends TestCase
{
    private Lexer $lexer;
    private Parser $parser;
    private HtmlRenderer $renderer;
    private HtmlRenderer $rendererAllowHtml;

    protected function setUp(): void
    {
        $this->lexer            = new Lexer();
        $this->parser           = new Parser();
        $this->renderer         = new HtmlRenderer();
        $this->rendererAllowHtml = new HtmlRenderer(allowRawHtml: true);
    }

    private function renderMarkdown(string $markdown): string
    {
        return $this->renderer->render(
            $this->parser->parse($this->lexer->tokenize($markdown))
        );
    }

    private function renderMarkdownAllowHtml(string $markdown): string
    {
        return $this->rendererAllowHtml->render(
            $this->parser->parse($this->lexer->tokenize($markdown))
        );
    }

    // ─── AC-1: Basic footnote reference and definition ───────────────────────

    /** AC-1: Basic footnote reference and definition produces correct HTML. */
    public function testBasicReferenceAndDefinition(): void
    {
        $md = "Text[^1].\n\n[^1]: First footnote.";
        $html = $this->renderMarkdown($md);
        $this->assertSame(
            '<p>Text<sup><a href="#fn-1" id="fnref-1">1</a></sup>.</p>'
            . '<section class="footnotes"><ol>'
            . '<li id="fn-1">First footnote. <a href="#fnref-1">↩</a></li>'
            . '</ol></section>',
            $html
        );
    }

    // ─── AC-2: Appearance-order numbering ────────────────────────────────────

    /** AC-2: References are numbered by order of first appearance in text, not definition order. */
    public function testAppearanceOrderNumbering(): void
    {
        $md = "Alpha[^b] and Beta[^a].\n\n[^a]: Definition A.\n[^b]: Definition B.";
        $html = $this->renderMarkdown($md);
        $this->assertSame(
            '<p>Alpha<sup><a href="#fn-1" id="fnref-1">1</a></sup> and Beta<sup><a href="#fn-2" id="fnref-2">2</a></sup>.</p>'
            . '<section class="footnotes"><ol>'
            . '<li id="fn-1">Definition B. <a href="#fnref-1">↩</a></li>'
            . '<li id="fn-2">Definition A. <a href="#fnref-2">↩</a></li>'
            . '</ol></section>',
            $html
        );
    }

    /** AC-2b: References in separate paragraphs get distinct numbers (counter must not reset between paragraphs). */
    public function testAppearanceOrderNumberingAcrossParagraphs(): void
    {
        $md = "First[^a].\n\nSecond[^b] and again[^b].\n\n[^a]: Def A.\n[^b]: Def B.";
        $html = $this->renderMarkdown($md);
        $this->assertSame(
            '<p>First<sup><a href="#fn-1" id="fnref-1">1</a></sup>.</p>'
            . '<p>Second<sup><a href="#fn-2" id="fnref-2">2</a></sup> and again<sup><a href="#fn-2" id="fnref-2-2">2</a></sup>.</p>'
            . '<section class="footnotes"><ol>'
            . '<li id="fn-1">Def A. <a href="#fnref-1">↩</a></li>'
            . '<li id="fn-2">Def B. <a href="#fnref-2">↩</a> <a href="#fnref-2-2">↩</a></li>'
            . '</ol></section>',
            $html
        );
    }

    // ─── AC-3: Same label twice — two back-links ─────────────────────────────

    /** AC-3: Same label referenced twice yields same number and two back-link anchors. */
    public function testSameLabelTwiceProducesTwoBackLinks(): void
    {
        $md = "First[^x] and second[^x].\n\n[^x]: Shared note.";
        $html = $this->renderMarkdown($md);
        $this->assertSame(
            '<p>First<sup><a href="#fn-1" id="fnref-1">1</a></sup> and second<sup><a href="#fn-1" id="fnref-1-2">1</a></sup>.</p>'
            . '<section class="footnotes"><ol>'
            . '<li id="fn-1">Shared note. <a href="#fnref-1">↩</a> <a href="#fnref-1-2">↩</a></li>'
            . '</ol></section>',
            $html
        );
    }

    /** Third occurrence of the same label produces fnref-1-3. */
    public function testThirdOccurrenceSuffix(): void
    {
        $md = "A[^n] B[^n] C[^n].\n\n[^n]: Triple note.";
        $html = $this->renderMarkdown($md);
        $this->assertStringContainsString('id="fnref-1-3"', $html);
        $this->assertStringContainsString('<a href="#fnref-1-3">↩</a>', $html);
    }

    // ─── AC-4: Undefined reference ───────────────────────────────────────────

    /** AC-4: Undefined footnote reference renders as literal text. */
    public function testUndefinedReferenceRendersLiteral(): void
    {
        $html = $this->renderMarkdown("See[^missing] for details.");
        $this->assertSame('<p>See[^missing] for details.</p>', $html);
        $this->assertStringNotContainsString('<section', $html);
    }

    // ─── AC-5: Definition without reference omitted ──────────────────────────

    /** AC-5: Unused footnote definition produces no list item and no section. */
    public function testUnusedDefinitionOmitted(): void
    {
        $html = $this->renderMarkdown("Just a paragraph.\n\n[^unused]: Never referenced.");
        $this->assertSame('<p>Just a paragraph.</p>', $html);
        $this->assertStringNotContainsString('section', $html);
        $this->assertStringNotContainsString('unused', $html);
    }

    // ─── AC-6: Multi-line footnote body ──────────────────────────────────────

    /** AC-6: 4-space continuation lines are merged into the footnote body. */
    public function testMultiLineBodyContinuation(): void
    {
        $md = "Text[^ml].\n\n[^ml]: First line.\n    Second line, same footnote.";
        $html = $this->renderMarkdown($md);
        $this->assertSame(
            '<p>Text<sup><a href="#fn-1" id="fnref-1">1</a></sup>.</p>'
            . '<section class="footnotes"><ol>'
            . '<li id="fn-1">First line. Second line, same footnote. <a href="#fnref-1">↩</a></li>'
            . '</ol></section>',
            $html
        );
    }

    // ─── AC-7: Footnote body with inline markup ───────────────────────────────

    /** AC-7: Inline markup inside footnote body is rendered through InlineParser. */
    public function testFootnoteBodyWithInlineMarkup(): void
    {
        $md = "Note[^fmt].\n\n[^fmt]: **Bold** and _italic_.";
        $html = $this->renderMarkdown($md);
        $this->assertSame(
            '<p>Note<sup><a href="#fn-1" id="fnref-1">1</a></sup>.</p>'
            . '<section class="footnotes"><ol>'
            . '<li id="fn-1"><strong>Bold</strong> and <em>italic</em>. <a href="#fnref-1">↩</a></li>'
            . '</ol></section>',
            $html
        );
    }

    // ─── AC-8: Definition line excluded from paragraph output ────────────────

    /** AC-8: FOOTNOTE_DEFINITION token does not appear as a paragraph. */
    public function testDefinitionLineExcludedFromParagraphOutput(): void
    {
        $html = $this->renderMarkdown("text\n\n[^1]: note.");
        // The definition creates a footnote section, but no paragraph for the def line itself.
        $this->assertStringNotContainsString('<p>[^1]', $html);
        $this->assertStringNotContainsString('<p>note.', $html);
        // "text" paragraph is present.
        $this->assertStringContainsString('<p>text</p>', $html);
    }

    // ─── AC-9: Section always at end of document ─────────────────────────────

    /** AC-9: FootnotesContainerNode is appended after all other block children. */
    public function testSectionAtEndOfDocument(): void
    {
        $md = "[^early]: Defined first.\n\nParagraph with reference[^early].\n\nAnother paragraph.";
        $html = $this->renderMarkdown($md);
        $this->assertSame(
            '<p>Paragraph with reference<sup><a href="#fn-1" id="fnref-1">1</a></sup>.</p>'
            . '<p>Another paragraph.</p>'
            . '<section class="footnotes"><ol>'
            . '<li id="fn-1">Defined first. <a href="#fnref-1">↩</a></li>'
            . '</ol></section>',
            $html
        );
    }

    /** EC-4: Definition placed before the referencing paragraph resolves correctly. */
    public function testDefinitionBeforeReferencingParagraph(): void
    {
        $md = "[^first]: Defined at top.\n\nParagraph with ref[^first].";
        $html = $this->renderMarkdown($md);
        $this->assertSame(
            '<p>Paragraph with ref<sup><a href="#fn-1" id="fnref-1">1</a></sup>.</p>'
            . '<section class="footnotes"><ol>'
            . '<li id="fn-1">Defined at top. <a href="#fnref-1">↩</a></li>'
            . '</ol></section>',
            $html
        );
    }

    // ─── AC-10: XSS — footnote body sanitization ─────────────────────────────

    /** AC-10a: Script tag in footnote body is escaped with default allowRawHtml: false. */
    public function testXssScriptTagInBodyEscaped(): void
    {
        $md = "Note[^xss].\n\n[^xss]: <script>alert(1)</script>";
        $html = $this->renderMarkdown($md);
        // Raw HTML tags must not appear — they are HTML-escaped.
        $this->assertStringNotContainsString('<script>', $html);
        // The escaped form &lt;script&gt; is acceptable (rendered as text, not executed).
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('fn-1', $html);
    }

    /** AC-10b: Event handler attribute in body is stripped when allowRawHtml: true. */
    public function testXssEventHandlerInBodyStripped(): void
    {
        $md = "Note[^xss2].\n\n[^xss2]: <img src=x onerror=\"alert(1)\">";
        $html = $this->renderMarkdownAllowHtml($md);
        $this->assertStringNotContainsString('onerror', $html);
    }

    // ─── AC-11: Case sensitivity ──────────────────────────────────────────────

    /** AC-11a: Labels with different casing are treated as distinct footnotes. */
    public function testDistinctCasedLabels(): void
    {
        $md = "Upper[^Foo] and lower[^foo].\n\n[^Foo]: Upper definition.\n[^foo]: Lower definition.";
        $html = $this->renderMarkdown($md);
        $this->assertStringContainsString('id="fnref-1"', $html);
        $this->assertStringContainsString('id="fnref-2"', $html);
        $this->assertStringContainsString('Upper definition.', $html);
        $this->assertStringContainsString('Lower definition.', $html);
    }

    /** AC-11b: Case mismatch between ref and definition — ref renders as literal text. */
    public function testLabelCaseSensitive(): void
    {
        $md = "Ref[^Bar].\n\n[^bar]: Definition for bar.";
        $html = $this->renderMarkdown($md);
        $this->assertSame('<p>Ref[^Bar].</p>', $html);
        $this->assertStringNotContainsString('section', $html);
    }

    // ─── AC-12: Valid label characters ───────────────────────────────────────

    /** AC-12: Label with hyphens and underscores resolves correctly. */
    public function testLabelWithHyphensAndUnderscores(): void
    {
        $md = "Ref[^my_note-2].\n\n[^my_note-2]: Body text.";
        $html = $this->renderMarkdown($md);
        $this->assertStringContainsString('href="#fn-1"', $html);
        $this->assertStringContainsString('Body text.', $html);
    }

    /** EC-1: All-digit footnote label is valid. */
    public function testLabelWithDigitsOnly(): void
    {
        $md = "Ref[^123].\n\n[^123]: All digits.";
        $html = $this->renderMarkdown($md);
        $this->assertSame(
            '<p>Ref<sup><a href="#fn-1" id="fnref-1">1</a></sup>.</p>'
            . '<section class="footnotes"><ol>'
            . '<li id="fn-1">All digits. <a href="#fnref-1">↩</a></li>'
            . '</ol></section>',
            $html
        );
    }

    /** EC-2: Hyphenated footnote label is valid. */
    public function testLabelWithHyphen(): void
    {
        $md = "Ref[^my-note].\n\n[^my-note]: Hyphenated label.";
        $html = $this->renderMarkdown($md);
        $this->assertSame(
            '<p>Ref<sup><a href="#fn-1" id="fnref-1">1</a></sup>.</p>'
            . '<section class="footnotes"><ol>'
            . '<li id="fn-1">Hyphenated label. <a href="#fnref-1">↩</a></li>'
            . '</ol></section>',
            $html
        );
    }

    /** AC-12: Label with a space does not match as a footnote reference. */
    public function testLabelWithSpace_notMatched(): void
    {
        $html = $this->renderMarkdown("Ref[^bad label].");
        $this->assertSame('<p>Ref[^bad label].</p>', $html);
    }

    /** EC-3: Definition with empty body (no text after colon) is rejected; ref renders as literal. */
    public function testEmptyBodyDefinitionNotMatched(): void
    {
        // [^empty]: (colon but no body text) — PATTERN_FOOTNOTE_DEF requires (.+) after \s+
        // The definition line does not match so it falls through to PARAGRAPH.
        $html = $this->renderMarkdown("Ref[^empty].\n\n[^empty]:");
        $this->assertStringNotContainsString('section', $html);
        $this->assertStringNotContainsString('<sup>', $html);
        // The ref renders as literal text (no matching definition).
        $this->assertStringContainsString('<p>Ref[^empty].</p>', $html);
    }

    // ─── AC-13: List item not tokenized as definition ─────────────────────────

    /** AC-13: Footnote-like syntax inside a list item body is treated as list content, not a block definition. */
    public function testListItemNotTokenizedAsDefinition(): void
    {
        $html = $this->renderMarkdown("- [^1]: This is a list item, not a footnote definition.");
        $this->assertStringContainsString('<ul><li>', $html);
        $this->assertStringContainsString('[^1]: This is a list item, not a footnote definition.', $html);
        $this->assertStringNotContainsString('<section', $html);
    }

    // ─── AC-14: No section when zero resolved footnotes ──────────────────────

    /** AC-14: Document with no footnote references produces no footnotes section. */
    public function testNoSectionWithZeroResolvedFootnotes(): void
    {
        $html = $this->renderMarkdown("Just text.");
        $this->assertSame('<p>Just text.</p>', $html);
        $this->assertStringNotContainsString('section', $html);
    }

    // ─── EC-5: Footnote ref inside emphasis ──────────────────────────────────

    /** EC-5: Footnote reference inside an emphasis span is resolved correctly. */
    public function testFootnoteRefInsideEmphasis(): void
    {
        $md = "_text[^1]_\n\n[^1]: Inline note.";
        $html = $this->renderMarkdown($md);
        $this->assertSame(
            '<p><em>text<sup><a href="#fn-1" id="fnref-1">1</a></sup></em></p>'
            . '<section class="footnotes"><ol>'
            . '<li id="fn-1">Inline note. <a href="#fnref-1">↩</a></li>'
            . '</ol></section>',
            $html
        );
    }

    // ─── EC-6: Footnote ref inside link label ────────────────────────────────

    /** EC-6: Footnote-like ref nested inside complex bracket syntax — actual behavior documented. */
    public function testFootnoteRefInsideLinkLabel_literalText(): void
    {
        // [text[^1]](url) — PATTERN_LINK expects [^\]]+ for the label, which stops at the first ].
        // The first ] is after [^1, so "text[^1" would be the label — but actually the outer [text
        // is not consumed first. The scanner at pos 0 sees [ then t (not ^), so the footnote branch
        // is skipped. PATTERN_LINK_ANGLE and PATTERN_LINK are tried but fail because
        // [text[^1]](url) has a [ before the label's ] which trips the regex. So [text goes to buffer,
        // then [^1] at the next [ is resolved as a footnote (label 1 is defined), producing a
        // FootnoteRefNode. Then ](https://example.com) is literal text.
        $md = "[text[^1]](https://example.com)\n\n[^1]: Note.";
        $html = $this->renderMarkdown($md);
        $this->assertStringContainsString('[text', $html);
        $this->assertStringContainsString('fn-1', $html);
        $this->assertStringNotContainsString('<a href="https://example.com">', $html);
    }

    // ─── EC-7: Very long label (>50 chars) not matched ────────────────────────

    /** EC-7: Label exceeding 50 characters is rejected by both PATTERN_FOOTNOTE_DEF and PATTERN_FOOTNOTE_REF. */
    public function testVeryLongLabel_notMatched(): void
    {
        // 51-character label (exceeds the {1,50} limit)
        $label = 'abcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxy'; // 51 chars
        $this->assertSame(51, strlen($label)); // guard: ensure label IS > 50
        $md = "Ref[^{$label}].\n\n[^{$label}]: Long label.";
        $html = $this->renderMarkdown($md);
        $this->assertStringNotContainsString('<section', $html);
        $this->assertStringNotContainsString('fn-1', $html);
    }

    // ─── Regression guards ───────────────────────────────────────────────────

    /** Regression: escaped opening bracket does not trigger footnote reference. */
    public function testEscapedBracketDoesNotTriggerFootnote(): void
    {
        $html = $this->renderMarkdown("\\[^1]");
        $this->assertSame('<p>[^1]</p>', $html);
        $this->assertStringNotContainsString('sup', $html);
    }

    /** Regression: [^...] inside a code span does not trigger footnote resolution. */
    public function testFootnoteRefInsideCodeSpanNotResolved(): void
    {
        $md = "See `[^1]`.\n\n[^1]: Note.";
        $html = $this->renderMarkdown($md);
        $this->assertStringContainsString('<code>[^1]</code>', $html);
    }

    /** Regression: footnote definition inside a column block is treated as literal text, not a real definition. */
    public function testFootnoteDefinitionInColumnTreatedAsLiteralText(): void
    {
        $md = "::: columns\n[^col]: Column footnote attempt.\n|||\nRight column.\n:::";
        $html = $this->renderMarkdown($md);
        $this->assertStringNotContainsString('<section', $html);
    }

    /** Security: label '__next_number__' must not corrupt the InlineParser sentinel key (DoS guard). */
    public function testNextNumberSentinelLabelTreatedAsLiteralText(): void
    {
        $md = "Text[^1].\n\n[^__next_number__]: Crafted.\n\n[^1]: Real note.";
        // Must not throw TypeError; [^__next_number__] definition is silently dropped as literal text.
        $html = $this->renderMarkdown($md);
        $this->assertStringContainsString('<section class="footnotes">', $html);
        $this->assertStringContainsString('Real note.', $html);
    }
}
