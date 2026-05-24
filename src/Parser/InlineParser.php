<?php

declare(strict_types=1);

namespace PhpMarkdown\Parser;

use PhpMarkdown\Node\Inline\CodeNode;
use PhpMarkdown\Node\Inline\EmphasisNode;
use PhpMarkdown\Node\Inline\HtmlEntityNode;
use PhpMarkdown\Node\Inline\ImageNode;
use PhpMarkdown\Node\Inline\LinkNode;
use PhpMarkdown\Node\Inline\RawHtmlInlineNode;
use PhpMarkdown\Node\Inline\StrikethroughNode;
use PhpMarkdown\Node\Inline\StrongNode;
use PhpMarkdown\Node\Inline\TextNode;
use PhpMarkdown\Node\InlineNodeInterface;

/**
 * Parses inline Markdown syntax into a sequence of InlineNode objects.
 *
 * Stateless recursive scanner: handles nested emphasis/strong correctly.
 */
final class InlineParser
{
    private const SAFE_SCHEMES = ['http', 'https', 'mailto', ''];

    /** Maximum nesting depth for recursive inline parsing (prevents stack overflow). */
    private const MAX_DEPTH = 64;

    /**
     * All 32 ASCII punctuation characters that may be backslash-escaped per CommonMark §2.4.
     * Membership test uses str_contains — no regex on the hot path.
     *
     * Note: \<newline> (hard line break) is intentionally NOT handled here.
     * The Lexer (Lexer.php lines 384–391) detects an odd number of trailing backslashes,
     * strips the final one, and sets meta['hard_break' => true] on the PARAGRAPH token.
     * By the time inline text reaches InlineParser::scan(), the \<newline> sequence has
     * already been consumed and removed. Adding \n here would be a no-op in normal flow
     * and would mishandle mid-paragraph \<newline> sequences that the Lexer did not strip.
     */
    private const ESCAPABLE_CHARS = '!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~';

    // Patterns use \G + offset param instead of substr() to avoid O(n²) string copies.
    // URL capture class excludes < > to block <javascript:...> autolink-style bypass.
    private const PATTERN_IMAGE    = '/\G!\[([^\]]*)\]\(([^)<>"\s]+)(?:\s+"([^"]*)")?\)/';
    private const PATTERN_LINK     = '/\G\[([^\]]+)\]\(([^)<>"\s]+)(?:\s+"([^"]*)")?\)/';
    private const PATTERN_REF_LINK = '/\G\[([^\]]+)\]\[([^\]]*)\]/';
    // CommonMark §6.6 — inline HTML tag: comment, closing tag, or opening/void tag.
    // \G anchors to current $pos offset. The s modifier allows . to span newlines in comments.
    private const PATTERN_RAW_HTML_INLINE =
        '/\G<(?:!--.*?-->|\/[a-zA-Z][^>]*>|[a-zA-Z][^>]*\/?>)/s';

    /**
     * Matches a well-formed HTML entity at the current position:
     *   - numeric decimal:  &#[0-9]{1,7};
     *   - numeric hex:      &#[xX][0-9A-Fa-f]{1,6};
     *   - named:            &[A-Za-z][A-Za-z0-9]{1,31};
     */
    private const PATTERN_ENTITY = '/\G&(?:#[0-9]{1,7}|#[xX][0-9A-Fa-f]{1,6}|[A-Za-z][A-Za-z0-9]{1,31});/';

    /** @var array<string, array{href: string, title: ?string}> */
    private array $refs = [];

    /**
     * @param array<string, array{href: string, title: ?string}> $refs
     * @return InlineNodeInterface[]
     */
    public function parse(string $text, array $refs = []): array
    {
        $this->refs = $refs;
        try {
            return $this->scan($text, 0);
        } finally {
            $this->refs = [];
        }
    }

    /**
     * @return InlineNodeInterface[]
     */
    private function scan(string $text, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            return $text !== '' ? [new TextNode($text)] : [];
        }

        $nodes = [];
        $len = strlen($text);
        $pos = 0;
        $buffer = '';

        while ($pos < $len) {
            $char = $text[$pos];

            // ── Backslash escape: \X where X is ASCII punctuation (CommonMark §2.4) ─
            // This branch MUST remain first in the loop — before backtick, &, [, *, _, ~, <.
            // An escaped backtick must suppress code-span opening; an escaped [ must suppress
            // link parsing. Any branch above this one would silently break those invariants.
            if ($char === '\\') {
                $pos = $this->parseBackslashEscape($text, $pos, $len, $buffer, $nodes);
                continue;
            }

            // ── Inline code: `code` or ``code`` ──────────────────────────────
            if ($char === '`') {
                $btCount = 0;
                $tmp = $pos;
                while ($tmp < $len && $text[$tmp] === '`') {
                    $btCount++;
                    $tmp++;
                }
                $closer = str_repeat('`', $btCount);
                $closePos = strpos($text, $closer, $tmp);
                if ($closePos !== false) {
                    $nodes = $this->flushBuffer($buffer, $nodes);
                    $buffer = '';
                    $nodes[] = new CodeNode(trim(substr($text, $tmp, $closePos - $tmp)));
                    $pos = $closePos + $btCount;
                    continue;
                }
                $buffer .= $char;
                $pos++;
                continue;
            }

            // ── HTML entity: &amp;  &#160;  &#x00A0; ─────────────────────────
            if ($char === '&') {
                if (!preg_match(self::PATTERN_ENTITY, $text, $m, 0, $pos)) {
                    // Bare & with no valid entity syntax → buffer as-is; renderer escapes it.
                    $buffer .= '&';
                    $pos++;
                    continue;
                }

                $raw = $m[0];

                // Determine whether this is a numeric or named entity.
                if ($raw[1] === '#') {
                    // Numeric entity — extract the codepoint.
                    $inner = substr($raw, 2, -1); // strip leading '&#' and trailing ';'
                    if ($inner[0] === 'x' || $inner[0] === 'X') {
                        $codepoint = hexdec(substr($inner, 1));
                    } else {
                        $codepoint = (int) $inner;
                    }

                    // Reject invalid / dangerous codepoints (CommonMark §2.5 & HTML5 §8.1.4).
                    $valid = !(
                        $codepoint === 0                             // NUL
                        || ($codepoint >= 0x0001 && $codepoint <= 0x001F
                            && $codepoint !== 0x0009                // TAB
                            && $codepoint !== 0x000A                // LF
                            && $codepoint !== 0x000D)               // CR
                        || $codepoint === 0x007F                    // DEL
                        || ($codepoint >= 0xD800 && $codepoint <= 0xDFFF) // surrogates
                        || ($codepoint >= 0xFDD0 && $codepoint <= 0xFDEF) // non-characters
                        || $codepoint === 0xFFFE
                        || $codepoint === 0xFFFF
                        || $codepoint > 0x10FFFF                    // beyond Unicode range
                    );

                    if (!$valid) {
                        $buffer .= $raw;
                        $pos    += strlen($raw);
                        continue;
                    }
                } else {
                    // Named entity — PHP recognises it if html_entity_decode changes it.
                    $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if ($decoded === $raw) {
                        // PHP did not recognise the name → treat as literal text.
                        $buffer .= $raw;
                        $pos    += strlen($raw);
                        continue;
                    }
                }

                // Valid entity: flush pending buffer, emit node, advance.
                $nodes   = $this->flushBuffer($buffer, $nodes);
                $buffer  = '';
                $nodes[] = new HtmlEntityNode($raw);
                $pos    += strlen($raw);
                continue;
            }

            // ── Image: ![alt](src "title"?) ───────────────────────────────────
            if ($char === '!' && ($pos + 1) < $len && $text[$pos + 1] === '[') {
                if (preg_match(self::PATTERN_IMAGE, $text, $m, 0, $pos)) {
                    $nodes = $this->flushBuffer($buffer, $nodes);
                    $buffer = '';
                    if ($this->isSafeUrl($m[2])) {
                        $nodes[] = new ImageNode(
                            src: $m[2],
                            alt: $m[1],
                            title: isset($m[3]) && $m[3] !== '' ? $m[3] : null,
                        );
                    } else {
                        $buffer .= $m[0]; // Unsafe src: render as literal text (XSS prevention)
                    }
                    $pos += strlen($m[0]);
                    continue;
                }
            }

            // ── Link: [text](url "title"?) and reference links ───────────────
            if ($char === '[') {
                // 1. Inline link — highest priority (CommonMark spec §6.3)
                if (preg_match(self::PATTERN_LINK, $text, $m, 0, $pos)) {
                    $nodes = $this->flushBuffer($buffer, $nodes);
                    $buffer = '';
                    $href = $m[2];
                    if ($this->isSafeUrl($href)) {
                        $title = isset($m[3]) && $m[3] !== '' ? $m[3] : null;
                        $nodes[] = new LinkNode(
                            href: $href,
                            children: $this->scan($m[1], $depth + 1),
                            title: $title,
                        );
                    } else {
                        $buffer .= $m[0]; // Unsafe URL: render as literal text (XSS prevention)
                    }
                    $pos += strlen($m[0]);
                    continue;
                }

                // 2. Reference link: [text][ref] or collapsed [text][]
                if (preg_match(self::PATTERN_REF_LINK, $text, $m, 0, $pos)) {
                    $nodes = $this->flushBuffer($buffer, $nodes);
                    $buffer = '';
                    $lookupKey = mb_strtolower($m[2] !== '' ? $m[2] : $m[1], 'UTF-8');
                    if (isset($this->refs[$lookupKey])) {
                        $def = $this->refs[$lookupKey];
                        if ($this->isSafeUrl($def['href'])) {
                            $nodes[] = new LinkNode(
                                href: $def['href'],
                                children: $this->scan($m[1], $depth + 1),
                                title: $def['title'],
                            );
                        } else {
                            $buffer .= $m[0]; // Unsafe href: render as literal text (XSS prevention)
                        }
                    } else {
                        $buffer .= $m[0]; // Unresolved reference → literal text
                    }
                    $pos += strlen($m[0]);
                    continue;
                }

                // 3. Shortcut reference: [text] — guard against O(n²) strpos on ref-free documents
                if ($this->refs !== [] && ($closePos = strpos($text, ']', $pos + 1)) !== false) {
                    $label = substr($text, $pos + 1, $closePos - $pos - 1);
                    $nextChar = $text[$closePos + 1] ?? '';
                    // Shortcut: next char must NOT be ( or [ (those were handled above)
                    if ($nextChar !== '(' && $nextChar !== '[' && $label !== '') {
                        $lookupKey = mb_strtolower($label, 'UTF-8');
                        if (isset($this->refs[$lookupKey])) {
                            $def = $this->refs[$lookupKey];
                            $nodes = $this->flushBuffer($buffer, $nodes);
                            $buffer = '';
                            if ($this->isSafeUrl($def['href'])) {
                                $nodes[] = new LinkNode(
                                    href: $def['href'],
                                    children: $this->scan($label, $depth + 1),
                                    title: $def['title'],
                                );
                            } else {
                                $buffer .= substr($text, $pos, $closePos - $pos + 1); // Unsafe href → literal
                            }
                            $pos = $closePos + 1;
                            continue;
                        }
                    }
                }
            }

            // ── Strikethrough: ~~text~~ ──────────────────────────────────────
            if ($char === '~' && isset($text[$pos + 1]) && $text[$pos + 1] === '~') {
                $closePos = strpos($text, '~~', $pos + 2);
                if ($closePos !== false) {
                    $inner = substr($text, $pos + 2, $closePos - $pos - 2);
                    if ($inner !== '') {
                        $nodes = $this->flushBuffer($buffer, $nodes);
                        $buffer = '';
                        $nodes[] = new StrikethroughNode($this->scan($inner, $depth + 1));
                        $pos = $closePos + 2;
                        continue;
                    }
                }
                $buffer .= '~~';
                $pos += 2;
                continue;
            }

            // ── Strong: **text** or __text__ ──────────────────────────────────
            if (
                ($char === '*' && isset($text[$pos + 1]) && $text[$pos + 1] === '*') ||
                ($char === '_' && isset($text[$pos + 1]) && $text[$pos + 1] === '_')
            ) {
                $marker = $char . $char;
                $closePos = strpos($text, $marker, $pos + 2);
                if ($closePos !== false) {
                    $nodes = $this->flushBuffer($buffer, $nodes);
                    $buffer = '';
                    $inner = substr($text, $pos + 2, $closePos - $pos - 2);
                    $nodes[] = new StrongNode($this->scan($inner, $depth + 1));
                    $pos = $closePos + 2;
                    continue;
                }
                $buffer .= $marker;
                $pos += 2;
                continue;
            }

            // ── Emphasis: *text* or _text_ ────────────────────────────────────
            if ($char === '*' || $char === '_') {
                $closePos = strpos($text, $char, $pos + 1);
                if ($closePos !== false) {
                    $nodes = $this->flushBuffer($buffer, $nodes);
                    $buffer = '';
                    $inner = substr($text, $pos + 1, $closePos - $pos - 1);
                    $nodes[] = new EmphasisNode($this->scan($inner, $depth + 1));
                    $pos = $closePos + 1;
                    continue;
                }
                $buffer .= $char;
                $pos++;
                continue;
            }

            // ── Raw inline HTML: <tag>, </tag>, <!-- comment --> (§6.6) ──────
            if ($char === '<') {
                if (preg_match(self::PATTERN_RAW_HTML_INLINE, $text, $m, 0, $pos)) {
                    $nodes = $this->flushBuffer($buffer, $nodes);
                    $buffer = '';
                    $nodes[] = new RawHtmlInlineNode($m[0]);
                    $pos += strlen($m[0]);
                    continue;
                }
                // No match (e.g. <3, < p>) — fall through to catch-all.
            }

            $buffer .= $char;
            $pos++;
        }

        return $this->flushBuffer($buffer, $nodes);
    }

    /**
     * Processes a backslash at $pos and returns the new cursor position.
     *
     * Three paths (CommonMark §2.4):
     *   1. $next is in ESCAPABLE_CHARS  → flush buffer, emit TextNode($next), advance 2.
     *   2. $next is not in ESCAPABLE_CHARS → append '\' to buffer, advance 1 (next char
     *      is processed normally on the following iteration).
     *   3. No following character (end of string) → append '\' to buffer, advance 1.
     *
     * $buffer and $nodes are passed by reference to match the mutation pattern used by
     * every other branch in scan().
     *
     * @param InlineNodeInterface[] $nodes
     */
    private function parseBackslashEscape(
        string $text,
        int    $pos,
        int    $len,
        string &$buffer,
        array  &$nodes,
    ): int {
        // No character follows the backslash — treat it as a literal backslash.
        if ($pos + 1 >= $len) {
            $buffer .= '\\';
            return $pos + 1;
        }

        $next = $text[$pos + 1];

        if (str_contains(self::ESCAPABLE_CHARS, $next)) {
            // Valid escape: flush any preceding text, then emit the literal punctuation.
            $nodes  = $this->flushBuffer($buffer, $nodes);
            $buffer = '';
            $nodes[] = new TextNode($next);
            return $pos + 2;
        }

        // Non-escapable character: the backslash is literal; $next is processed next iteration.
        $buffer .= '\\';
        return $pos + 1;
    }

    /**
     * @param InlineNodeInterface[] $nodes
     * @return InlineNodeInterface[]
     */
    private function flushBuffer(string $buffer, array $nodes): array
    {
        if ($buffer !== '') {
            $nodes[] = new TextNode($buffer);
        }
        return $nodes;
    }

    private function isSafeUrl(string $url): bool
    {
        // Reject control chars (raw or percent-encoded: %00, %0a, %0d…).
        if (preg_match('/[\x00-\x20\x7F]/', rawurldecode($url))) {
            return false;
        }
        // Reject protocol-relative URLs (//evil.com inherits the host page's scheme).
        if (str_starts_with($url, '//') || str_starts_with($url, '\\')) {
            return false;
        }
        // parse_url() returning false means malformed URL — reject rather than allow.
        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        return in_array($scheme, self::SAFE_SCHEMES, true);
    }
}
