<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit;

use PhpMarkdown\Lexer\Lexer;
use PhpMarkdown\Lexer\Token;
use PhpMarkdown\Lexer\TokenType;
use PhpMarkdown\Parser\Parser;
use PhpMarkdown\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

final class FencedCodeTildeTest extends TestCase
{
    private Lexer $lexer;
    private Parser $parser;
    private HtmlRenderer $renderer;

    protected function setUp(): void
    {
        $this->lexer    = new Lexer();
        $this->parser   = new Parser();
        $this->renderer = new HtmlRenderer();
    }

    private function render(string $markdown): string
    {
        return $this->renderer->render(
            $this->parser->parse($this->lexer->tokenize($markdown))
        );
    }

    /** @return Token[] */
    private function tokenize(string $markdown): array
    {
        return $this->lexer->tokenize($markdown);
    }

    /**
     * TC-01: Basic tilde fence without info string produces plain code block.
     */
    public function testTC01BasicTildeFenceNoInfoString(): void
    {
        $html = $this->render("~~~\nfoo\n~~~");

        $this->assertSame('<pre><code>foo</code></pre>', $html);
    }

    /**
     * TC-02: Tilde fence with info string produces class="language-php".
     */
    public function testTC02TildeFenceWithInfoString(): void
    {
        $html = $this->render("~~~php\necho 1;\n~~~");

        $this->assertSame('<pre><code class="language-php">echo 1;</code></pre>', $html);
    }

    /**
     * TC-03: A 4-tilde closing fence can close a 3-tilde opening fence.
     */
    public function testTC03FourTildeCloseThreeTildeOpen(): void
    {
        $html = $this->render("~~~\nfoo\n~~~~");

        $this->assertSame('<pre><code>foo</code></pre>', $html);
    }

    /**
     * TC-04: A 3-tilde close cannot close a 4-tilde open; entire block is unclosed.
     */
    public function testTC04ThreeTildeCannotCloseFourTildeOpen(): void
    {
        // The ~~~ line inside is treated as content, block is unclosed at EOF.
        $html = $this->render("~~~~\nfoo\n~~~\nbar");

        $this->assertSame("<pre><code>foo\n~~~\nbar</code></pre>", $html);
    }

    /**
     * TC-05: A backtick fence line inside a tilde fence is treated as content.
     */
    public function testTC05BacktickLineIsContentInsideTildeFence(): void
    {
        $html = $this->render("~~~\nfoo\n```\n~~~");

        $this->assertSame("<pre><code>foo\n```</code></pre>", $html);
    }

    /**
     * TC-06: A tilde fence line inside a backtick fence is treated as content.
     */
    public function testTC06TildeLineIsContentInsideBacktickFence(): void
    {
        $html = $this->render("```\nfoo\n~~~\n```");

        $this->assertSame("<pre><code>foo\n~~~</code></pre>", $html);
    }

    /**
     * TC-07: Up to 3 leading spaces on fence lines are allowed.
     */
    public function testTC07ThreeSpaceIndentOnFenceLines(): void
    {
        $html = $this->render("   ~~~\nfoo\n   ~~~");

        $this->assertSame('<pre><code>foo</code></pre>', $html);
    }

    /**
     * TC-08: Four leading spaces make ~~~ an indented code block, not a fence opener.
     */
    public function testTC08FourSpaceIndentProducesIndentedCodeBlock(): void
    {
        // "    ~~~" is an indented code block containing "~~~" as content.
        $html = $this->render("    ~~~\n");

        $this->assertSame("<pre><code>~~~\n</code></pre>", $html);
        $this->assertStringNotContainsString('</code></pre><pre><code>', $html);
    }

    /**
     * TC-09: Unclosed tilde fence at EOF is emitted via EOF flush.
     */
    public function testTC09UnclosedTildeFenceEmittedAtEof(): void
    {
        $html = $this->render("~~~\nfoo\nbar");

        $this->assertSame("<pre><code>foo\nbar</code></pre>", $html);
    }

    /**
     * TC-10: A 4-tilde open fence is closed by a 4-tilde close fence.
     */
    public function testTC10FourTildeOpenClosedByFourTildeClose(): void
    {
        $html = $this->render("~~~~\nfoo\n~~~~");

        $this->assertSame('<pre><code>foo</code></pre>', $html);
    }

    /**
     * TC-11: HTML special characters in tilde fence content are escaped.
     */
    public function testTC11HtmlCharactersEscaped(): void
    {
        $html = $this->render("~~~\n<b>bold</b> & \"quotes\"\n~~~");

        $this->assertSame(
            '<pre><code>&lt;b&gt;bold&lt;/b&gt; &amp; &quot;quotes&quot;</code></pre>',
            $html,
        );
    }

    /**
     * TC-12: Backtick in tilde fence info string is allowed by the lexer;
     * the renderer strips it (only [A-Za-z0-9_-] kept) → no class attribute.
     */
    public function testTC12BacktickInTildeInfoStringStrippedByRenderer(): void
    {
        // Info string is a single backtick; after renderer sanitisation it becomes ''.
        $html = $this->render("~~~ `\nconsole.log(1);\n~~~");

        $this->assertSame('<pre><code>console.log(1);</code></pre>', $html);
    }

    /**
     * TC-13: Two tildes (~~ ) are not a valid fence opener (minimum is 3).
     */
    public function testTC13TwoTildesAreNotAFenceOpener(): void
    {
        $tokens = $this->tokenize("~~\nfoo\n~~");

        $fencedTokens = array_filter($tokens, fn ($t) => $t->type === TokenType::FENCED_CODE);
        $this->assertCount(0, $fencedTokens, 'Two tildes must not produce a FENCED_CODE token');
    }

    /**
     * TC-14: Adjacent backtick fence then tilde fence each produce their own code block.
     */
    public function testTC14AdjacentBacktickThenTildeFence(): void
    {
        $html = $this->render("```\nhello\n```\n~~~\nworld\n~~~");

        $this->assertSame(
            '<pre><code>hello</code></pre><pre><code>world</code></pre>',
            $html,
        );
    }

    /**
     * TC-15: A paragraph preceding a tilde fence renders both elements correctly.
     */
    public function testTC15ParagraphBeforeTildeFence(): void
    {
        $html = $this->render("paragraph text\n\n~~~\ncode\n~~~");

        $this->assertSame(
            '<p>paragraph text</p><pre><code>code</code></pre>',
            $html,
        );
    }

    // --- Lexer-level assertions ---

    /**
     * Tilde fence produces a FENCED_CODE token with the correct language.
     */
    public function testTildeFenceLexerTokenHasCorrectLanguage(): void
    {
        $tokens = $this->tokenize("~~~php\nfoo\n~~~");

        $this->assertCount(1, $tokens);
        $this->assertSame(TokenType::FENCED_CODE, $tokens[0]->type);
        $this->assertSame('php', $tokens[0]->meta['language']);
        $this->assertSame('foo', $tokens[0]->content);
    }

    /**
     * Tilde fence without info string has an empty language meta.
     */
    public function testTildeFenceLexerTokenEmptyLanguageWhenNoInfoString(): void
    {
        $tokens = $this->tokenize("~~~\nfoo\n~~~");

        $this->assertSame('', $tokens[0]->meta['language']);
    }

    /**
     * Backtick fence and tilde fence are independent: one does not close the other.
     */
    public function testBacktickFenceAndTildeFenceAreIndependent(): void
    {
        // Backtick open, then tilde close attempt — tilde line becomes content.
        $tokens = $this->tokenize("```\nfoo\n~~~\n```");

        $this->assertCount(1, $tokens);
        $this->assertSame(TokenType::FENCED_CODE, $tokens[0]->type);
        $this->assertSame("foo\n~~~", $tokens[0]->content);
    }
}
