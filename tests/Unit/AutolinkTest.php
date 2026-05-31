<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit;

use PhpMarkdown\Node\Block\DocumentNode;
use PhpMarkdown\Node\Block\ParagraphNode;
use PhpMarkdown\Node\Inline\AutolinkNode;
use PhpMarkdown\Node\Inline\TextNode;
use PhpMarkdown\Parser\InlineParser;
use PhpMarkdown\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

final class AutolinkTest extends TestCase
{
    private InlineParser $parser;
    private HtmlRenderer $renderer;

    protected function setUp(): void
    {
        $this->parser   = new InlineParser();
        $this->renderer = new HtmlRenderer();
    }

    /**
     * Parse inline text and render via a minimal DocumentNode wrapping a ParagraphNode.
     * Returns the inner content of the <p> tag.
     */
    private function parse(string $input): string
    {
        $nodes    = $this->parser->parse($input);
        $para     = new ParagraphNode($nodes);
        $document = new DocumentNode([$para]);
        $html     = $this->renderer->render($document);
        // Strip outer <p>...</p> (and optional trailing newline) to get the inner rendered content.
        return preg_replace('/^<p>(.*)<\/p>\n?$/s', '$1', $html) ?? $html;
    }

    // ── URL autolinks ─────────────────────────────────────────────────────────

    public function test_url_autolink_basic(): void
    {
        $result = $this->parse('<http://example.com>');
        $this->assertSame('<a href="http://example.com">http://example.com</a>', $result);
    }

    public function test_url_autolink_https(): void
    {
        $result = $this->parse('<https://example.com/path?q=1>');
        $this->assertSame('<a href="https://example.com/path?q=1">https://example.com/path?q=1</a>', $result);
    }

    public function test_url_autolink_custom_scheme(): void
    {
        $result = $this->parse('<ftp://files.example.com>');
        $this->assertSame('<a href="ftp://files.example.com">ftp://files.example.com</a>', $result);
    }

    public function test_url_autolink_short_scheme(): void
    {
        // Minimum valid scheme per CommonMark §6.9: 2–32 chars (letter + 1–31 alphanumeric/+-.).
        // Pattern: [a-zA-Z][a-zA-Z0-9+\-.]{1,31} — requires at least 2 chars before the colon.
        $result = $this->parse('<ab:path>');
        $this->assertSame('<a href="ab:path">ab:path</a>', $result);
    }

    public function test_url_autolink_scheme_too_long(): void
    {
        // 33-char scheme: 'abcdefghijklmnopqrstuvwxyzabcdef' (32 chars) + 'g' = 33 chars — over limit.
        // Pattern allows letter + 1–31 chars = 2–32 total; 33 chars must NOT match.
        $input  = '<abcdefghijklmnopqrstuvwxyzabcdefg:x>';
        $result = $this->parse($input);
        // Must not produce an autolink anchor.
        $this->assertStringNotContainsString('<a href=', $result);
    }

    public function test_url_autolink_rejects_space_in_url(): void
    {
        $result = $this->parse('<http://foo bar>');
        $this->assertStringNotContainsString('<a href=', $result);
    }

    public function test_url_autolink_rejects_angle_bracket(): void
    {
        // '<' inside the URL path must not match.
        $result = $this->parse('<http://foo<bar>');
        $this->assertStringNotContainsString('<a href=', $result);
    }

    // ── Email autolinks ───────────────────────────────────────────────────────

    public function test_email_autolink_basic(): void
    {
        $result = $this->parse('<user@example.com>');
        $this->assertSame('<a href="mailto:user@example.com">user@example.com</a>', $result);
    }

    public function test_email_autolink_subdomain(): void
    {
        $result = $this->parse('<user@mail.example.com>');
        $this->assertSame('<a href="mailto:user@mail.example.com">user@mail.example.com</a>', $result);
    }

    public function test_email_autolink_complex_local_part(): void
    {
        $result = $this->parse('<user+tag@example.com>');
        $this->assertSame('<a href="mailto:user+tag@example.com">user+tag@example.com</a>', $result);
    }

    public function test_email_autolink_rejects_no_domain(): void
    {
        $result = $this->parse('<user@>');
        $this->assertStringNotContainsString('<a href=', $result);
    }

    public function test_email_autolink_rejects_bare_at(): void
    {
        $result = $this->parse('<@example.com>');
        $this->assertStringNotContainsString('<a href=', $result);
    }

    // ── HTML escaping ─────────────────────────────────────────────────────────

    public function test_autolink_html_escaping_in_href(): void
    {
        // Ampersand in URL must be escaped as &amp; in both href and text.
        $result = $this->parse('<http://example.com?a=1&b=2>');
        $this->assertSame(
            '<a href="http://example.com?a=1&amp;b=2">http://example.com?a=1&amp;b=2</a>',
            $result,
        );
    }

    public function test_autolink_html_escaping_in_email(): void
    {
        // The & character is valid in the email local-part (pattern includes &).
        // It must be HTML-escaped as &amp; in both the href attribute and the link text.
        // This exercises esc() on both href and text for email autolinks.
        $result = $this->parse('<a&b@example.com>');
        $this->assertSame(
            '<a href="mailto:a&amp;b@example.com">a&amp;b@example.com</a>',
            $result,
        );
    }

    // ── Disambiguation from raw HTML ──────────────────────────────────────────

    public function test_autolink_not_confused_with_raw_html(): void
    {
        // <a href="x"> is a raw HTML tag, not an autolink. With allowRawHtml=false (default)
        // it is escaped as literal text.
        $result = $this->parse('<a href="x">');
        $this->assertStringNotContainsString('mailto:', $result);
        // The angle bracket is escaped to &lt; (renderer escapes raw HTML in safe mode).
        $this->assertStringContainsString('&lt;', $result);
    }

    // ── Security: javascript: scheme ─────────────────────────────────────────

    public function test_url_autolink_rejects_javascript_scheme(): void
    {
        // CommonMark spec does not filter schemes at parse time; javascript: is a valid scheme
        // per PATTERN_AUTOLINK_URL. The renderer calls esc() on both href and text but does NOT
        // suppress the link. Consumers may add their own scheme filtering on top.
        $result = $this->parse('<javascript:alert(1)>');
        $this->assertStringContainsString('<a href=', $result);
        $this->assertStringContainsString('javascript:alert(1)', $result);
    }

    // ── Inline context ────────────────────────────────────────────────────────

    public function test_autolink_in_surrounding_text(): void
    {
        $result = $this->parse('foo <http://x.com> bar');
        $this->assertSame('foo <a href="http://x.com">http://x.com</a> bar', $result);
    }

    public function test_multiple_autolinks_in_one_line(): void
    {
        // <http://a.com> → URL autolink.
        // <mailto:b@c.com> → matches URL pattern (scheme 'mailto'), NOT email pattern.
        //   The captured group is the full content 'mailto:b@c.com'; isEmail=false so
        //   the renderer outputs href="mailto:b@c.com" (the raw URL verbatim, escaped).
        $result = $this->parse('<http://a.com> and <mailto:b@c.com>');
        $this->assertStringContainsString('<a href="http://a.com">http://a.com</a>', $result);
        $this->assertStringContainsString('<a href="mailto:b@c.com">mailto:b@c.com</a>', $result);
    }

    // ── Node structure ────────────────────────────────────────────────────────

    public function test_autolink_node_is_email_flag(): void
    {
        $nodes = $this->parser->parse('<user@example.com>');
        $this->assertCount(1, $nodes);
        $this->assertInstanceOf(AutolinkNode::class, $nodes[0]);
        $this->assertSame('user@example.com', $nodes[0]->url);
        $this->assertTrue($nodes[0]->isEmail);
    }

    public function test_autolink_node_url_flag(): void
    {
        $nodes = $this->parser->parse('<https://example.com>');
        $this->assertCount(1, $nodes);
        $this->assertInstanceOf(AutolinkNode::class, $nodes[0]);
        $this->assertSame('https://example.com', $nodes[0]->url);
        $this->assertFalse($nodes[0]->isEmail);
    }

    public function test_autolink_surrounded_by_text_nodes(): void
    {
        $nodes = $this->parser->parse('foo <http://x.com> bar');
        $this->assertCount(3, $nodes);
        $this->assertInstanceOf(TextNode::class, $nodes[0]);
        $this->assertInstanceOf(AutolinkNode::class, $nodes[1]);
        $this->assertInstanceOf(TextNode::class, $nodes[2]);
        $this->assertSame('foo ', $nodes[0]->text);
        $this->assertSame(' bar', $nodes[2]->text);
    }

    // ── Blocker: no URI scheme ────────────────────────────────────────────────

    public function test_url_autolink_rejects_no_scheme(): void
    {
        // <example.com> has no colon, so no URI scheme — must NOT produce an autolink.
        // Distinct from test_url_autolink_scheme_too_long which has a colon but an over-length scheme.
        $result = $this->parse('<example.com>');
        $this->assertStringNotContainsString('<a href=', $result);
    }

    // ── Blocker: autolink inside emphasis ─────────────────────────────────────

    public function test_url_autolink_inside_emphasis(): void
    {
        // Leaf-inline autolink wrapped by emphasis: *<https://example.com>*
        $nodes    = $this->parser->parse('*<https://example.com>*');
        $para     = new ParagraphNode($nodes);
        $document = new DocumentNode([$para]);
        $html     = $this->renderer->render($document);
        $this->assertSame(
            "<p><em><a href=\"https://example.com\">https://example.com</a></em></p>\n",
            $html,
        );
    }

    // ── Edge cases ────────────────────────────────────────────────────────────

    public function test_url_autolink_rejects_control_char_in_uri(): void
    {
        // ASCII control character \x01 inside URI — must not produce an autolink.
        $result = $this->parse("<http://exa\x01mple.com>");
        $this->assertStringNotContainsString('<a href=', $result);
    }

    public function test_url_autolink_rejects_null_byte_in_uri(): void
    {
        // Null byte \x00 inside URI — must not produce an autolink.
        $result = $this->parse("<http://exa\x00mple.com>");
        $this->assertStringNotContainsString('<a href=', $result);
    }

    public function test_url_autolink_case_insensitive_scheme(): void
    {
        // CommonMark §6.9: scheme comparison is case-insensitive; upper-case schemes are valid.
        // The original casing of the URL must be preserved in href and link text.
        $result = $this->parse('<HTTP://example.com>');
        $this->assertStringContainsString('<a href=', $result);
        $this->assertStringContainsString('HTTP://example.com', $result);
    }

    public function test_url_autolink_rejects_empty_brackets(): void
    {
        // <> contains no content — must not produce an autolink.
        $result = $this->parse('<>');
        $this->assertStringNotContainsString('<a href=', $result);
    }

    public function test_email_autolink_rejects_gt_in_local_part(): void
    {
        // <us>er@example.com> — the first '>' closes the angle bracket before the '@',
        // so no full-span AutolinkNode should be produced.
        $nodes = $this->parser->parse('<us>er@example.com>');
        $hasAutolink = false;
        foreach ($nodes as $node) {
            if ($node instanceof AutolinkNode) {
                $hasAutolink = true;
            }
        }
        $this->assertFalse($hasAutolink);
    }

    // ── Positional ────────────────────────────────────────────────────────────

    public function test_adjacent_autolinks(): void
    {
        // Two autolinks with no separator — both must be rendered as separate <a> tags.
        $result = $this->parse('<https://a.com><https://b.com>');
        $this->assertStringContainsString('<a href="https://a.com">https://a.com</a>', $result);
        $this->assertStringContainsString('<a href="https://b.com">https://b.com</a>', $result);
    }

    public function test_autolink_at_paragraph_start(): void
    {
        $nodes    = $this->parser->parse('<https://example.com> text after');
        $para     = new ParagraphNode($nodes);
        $document = new DocumentNode([$para]);
        $html     = $this->renderer->render($document);
        $this->assertStringStartsWith('<p><a href=', $html);
    }

    public function test_autolink_at_paragraph_end(): void
    {
        $nodes    = $this->parser->parse('text before <https://example.com>');
        $para     = new ParagraphNode($nodes);
        $document = new DocumentNode([$para]);
        $html     = $this->renderer->render($document);
        $this->assertStringEndsWith("</a></p>\n", $html);
    }

    public function test_url_autolink_very_long_url(): void
    {
        // A 2000-character path segment — the rendered href must contain the full URL untruncated.
        $longPath = str_repeat('x', 2000);
        $url      = 'https://' . $longPath;
        $result   = $this->parse('<' . $url . '>');
        $this->assertStringContainsString('href="' . $url . '"', $result);
    }
}
