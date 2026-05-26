<?php

declare(strict_types=1);

namespace PhpMarkdown\Parser;

use PhpMarkdown\Node\Inline\AutolinkNode;
use PhpMarkdown\Node\Inline\CodeNode;
use PhpMarkdown\Node\Inline\FootnoteRefNode;
use PhpMarkdown\Node\Inline\HtmlEntityNode;
use PhpMarkdown\Node\Inline\ImageNode;
use PhpMarkdown\Node\Inline\LinkNode;
use PhpMarkdown\Node\Inline\RawHtmlInlineNode;
use PhpMarkdown\Node\Inline\StrikethroughNode;
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
    // URL capture class excludes < > " ' to block <javascript:...> autolink-style bypass and
    // prevent url'title' (no space) absorbing the single-quote title delimiter into the URL.
    // Title group supports three CommonMark §6.6 delimiters: "...", '...', (...).
    // Capture groups: [1]=text/alt, [2]=url, [3]=dq-title, [4]=sq-title, [5]=paren-title.
    // Single-quote body: (?:[^'\\]|\\.)*  — escape-aware, allows \' inside.
    // Paren body: (?:[^()\\]|\\.)*  — blocks unescaped ( and ) so (bad(title) is invalid (AC-10).
    // \s* before final ) tolerates optional trailing spaces (EDGE-2).
    private const PATTERN_IMAGE    = '/\G!\[([^\]]*)\]\(([^)<>"\'\\s]+)(?:\s+(?:"([^"]*)"|\'((?:[^\'\\\\]|\\\\.)*)\'|\(((?:[^()\\\\]|\\\\.)*)\)))?\s*\)/';
    private const PATTERN_LINK     = '/\G\[([^\]]+)\]\(([^)<>"\'\\s]+)(?:\s+(?:"([^"]*)"|\'((?:[^\'\\\\]|\\\\.)*)\'|\(((?:[^()\\\\]|\\\\.)*)\)))?\s*\)/';
    // Angle-bracket URL variants: [text](<url with spaces>) and ![alt](<src>).
    // URL group allows spaces and most chars; blocks literal < > and newlines; \\ . handles escapes.
    // Title group: same three-delimiter form as plain patterns (groups 3/4/5).
    // \s* before final ) tolerates optional trailing spaces (EDGE-2).
    private const PATTERN_IMAGE_ANGLE = '/\G!\[([^\]]*)\]\(<((?:[^<>\n\\\\]|\\\\.)*)>(?:\s+(?:"([^"]*)"|\'((?:[^\'\\\\]|\\\\.)*)\'|\(((?:[^()\\\\]|\\\\.)*)\)))?\s*\)/';
    private const PATTERN_LINK_ANGLE  = '/\G\[([^\]]+)\]\(<((?:[^<>\n\\\\]|\\\\.)*)>(?:\s+(?:"([^"]*)"|\'((?:[^\'\\\\]|\\\\.)*)\'|\(((?:[^()\\\\]|\\\\.)*)\)))?\s*\)/';
    private const PATTERN_REF_LINK  = '/\G\[([^\]]+)\]\[([^\]]*)\]/';
    private const PATTERN_REF_IMAGE = '/\G!\[([^\]]*)\]\[([^\]]*)\]/';
    // CommonMark §6.9 — autolinks: <scheme:path> and <email>.
    // Scheme: letter followed by 1–31 chars of [letter digit + - .], then colon.
    // Path: any char except NUL, space, <, >.
    // SAFETY: These checks MUST run before PATTERN_RAW_HTML_INLINE in scan() — the raw-HTML
    // alternative [a-zA-Z][^>]*> would swallow <http://example.com> as an opening tag.
    private const PATTERN_AUTOLINK_URL =
        '/\G<([a-zA-Z][a-zA-Z0-9+\-.]{1,31}:[^\x00-\x20<>]*)>/';

    // Email autolink: <local@domain> with CommonMark §6.9 local-part and domain rules.
    private const PATTERN_AUTOLINK_EMAIL =
        "/\G<([a-zA-Z0-9.!#\$%&'*+\/=?^_{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*)>/";

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

    /** Matches an inline footnote reference: [^label] anchored at current position. */
    private const PATTERN_FOOTNOTE_REF = '/\G\[\^([A-Za-z0-9_-]{1,50})\]/';

    /** @var array<string, array{href: string, title: ?string}> */
    private array $refs = [];

    /** @var array<string, array{body: string, number?: int, occurrences?: int}> */
    private array $footnoteDefinitions = [];

    /**
     * @param array<string, array{href: string, title: ?string}> $refs
     * @param array<string, array{body: string, number?: int, occurrences?: int}> $footnoteDefinitions
     *        Keyed by raw (case-sensitive) label. Mutated during scan():
     *        - 'number' int is assigned on first reference encounter.
     *        - 'occurrences' int is incremented on every encounter.
     * @return InlineNodeInterface[]
     */
    public function parse(string $text, array $refs = [], array &$footnoteDefinitions = []): array
    {
        $this->refs               = $refs;
        $this->footnoteDefinitions = &$footnoteDefinitions;
        try {
            return $this->scan($text, 0);
        } finally {
            $this->refs = [];
            // Unset the reference to the caller's array before re-initialising the property,
            // so the caller's array retains the mutations (assigned numbers, occurrence counts).
            unset($this->footnoteDefinitions);
            $this->footnoteDefinitions = [];
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

        /** @var list<DelimiterRun|InlineNodeInterface> $tokens */
        $tokens = [];
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
                $pos = $this->parseBackslashEscape($text, $pos, $len, $buffer, $tokens);
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
                    $tokens = $this->flushBuffer($buffer, $tokens);
                    $buffer = '';
                    $raw = substr($text, $tmp, $closePos - $tmp);
                    // CommonMark §6.1 — three-step normalisation
                    // Step 1: replace line endings with single space
                    $content = str_replace(["\r\n", "\r", "\n"], ' ', $raw);
                    // Step 1b: all-spaces content collapses to a single space
                    if (strlen($content) > 0 && ltrim($content) === '') {
                        $content = ' ';
                    }
                    // Step 2: if not all-spaces AND starts+ends with space, strip one each side
                    if (
                        strlen($content) > 0
                        && $content[0] === ' '
                        && $content[strlen($content) - 1] === ' '
                        && ltrim($content) !== ''
                    ) {
                        $content = substr($content, 1, strlen($content) - 2);
                    }
                    $tokens[] = new CodeNode($content);
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
                $tokens  = $this->flushBuffer($buffer, $tokens);
                $buffer  = '';
                $tokens[] = new HtmlEntityNode($raw);
                $pos    += strlen($raw);
                continue;
            }

            // ── Image: ![alt](src "title"?) ───────────────────────────────────
            if ($char === '!' && ($pos + 1) < $len && $text[$pos + 1] === '[') {
                // Angle-bracket URL form: ![alt](<src> or <src "title">) — checked first.
                if (preg_match(self::PATTERN_IMAGE_ANGLE, $text, $m, 0, $pos)) {
                    $tokens = $this->flushBuffer($buffer, $tokens);
                    $buffer = '';
                    $src = stripslashes($m[2]);
                    if ($this->isSafeAngleBracketUrl($src)) {
                        $rawTitle = ($m[3] ?? '') !== '' ? $m[3] : (($m[4] ?? '') !== '' ? $m[4] : (($m[5] ?? '') !== '' ? $m[5] : null));
                        $tokens[] = new ImageNode(
                            src: $src,
                            alt: $m[1],
                            title: $rawTitle !== null ? stripslashes($rawTitle) : null,
                        );
                    } else {
                        $buffer .= $m[0]; // Unsafe src: render as literal text (XSS prevention)
                    }
                    $pos += strlen($m[0]);
                    continue;
                }
                if (preg_match(self::PATTERN_IMAGE, $text, $m, 0, $pos)) {
                    $tokens = $this->flushBuffer($buffer, $tokens);
                    $buffer = '';
                    if ($this->isSafeUrl($m[2])) {
                        $rawTitle = ($m[3] ?? '') !== '' ? $m[3] : (($m[4] ?? '') !== '' ? $m[4] : (($m[5] ?? '') !== '' ? $m[5] : null));
                        $tokens[] = new ImageNode(
                            src: $m[2],
                            alt: $m[1],
                            title: $rawTitle !== null ? stripslashes($rawTitle) : null,
                        );
                    } else {
                        $buffer .= $m[0]; // Unsafe src: render as literal text (XSS prevention)
                    }
                    $pos += strlen($m[0]);
                    continue;
                }
                // Image reference: ![alt][ref] or collapsed ![alt][]
                if ($this->refs !== [] && preg_match(self::PATTERN_REF_IMAGE, $text, $m, 0, $pos)) {
                    $tokens = $this->flushBuffer($buffer, $tokens);
                    $buffer = '';
                    $lookupKey = mb_strtolower($m[2] !== '' ? $m[2] : $m[1], 'UTF-8');
                    if (isset($this->refs[$lookupKey])) {
                        $def = $this->refs[$lookupKey];
                        if ($this->isSafeUrl($def['href'])) {
                            $tokens[] = new ImageNode(
                                src: $def['href'],
                                alt: $m[1],
                                title: $def['title'],
                            );
                        } else {
                            $buffer .= $m[0]; // Unsafe src: render as literal text (XSS prevention)
                        }
                    } else {
                        $buffer .= $m[0]; // Unresolved reference → literal text
                    }
                    $pos += strlen($m[0]);
                    continue;
                }
                // Image shortcut reference: ![alt] (no second bracket pair)
                if ($this->refs !== [] && ($closePos = strpos($text, ']', $pos + 2)) !== false) {
                    $alt = substr($text, $pos + 2, $closePos - $pos - 2);
                    $nextChar = $text[$closePos + 1] ?? '';
                    if ($nextChar !== '(' && $nextChar !== '[' && $alt !== '') {
                        $lookupKey = mb_strtolower($alt, 'UTF-8');
                        if (isset($this->refs[$lookupKey])) {
                            $def = $this->refs[$lookupKey];
                            $tokens = $this->flushBuffer($buffer, $tokens);
                            $buffer = '';
                            if ($this->isSafeUrl($def['href'])) {
                                $tokens[] = new ImageNode(
                                    src: $def['href'],
                                    alt: $alt,
                                    title: $def['title'],
                                );
                            } else {
                                $buffer .= substr($text, $pos, $closePos - $pos + 1); // Unsafe src → literal
                            }
                            $pos = $closePos + 1;
                            continue;
                        }
                    }
                }
            }

            // ── Link: [text](url "title"?) and reference links ───────────────
            if ($char === '[') {
                // ── Footnote reference: [^label] ─────────────────────────────────
                // Gate on $text[$pos+1] === '^' to avoid regex overhead on every [.
                if (isset($text[$pos + 1]) && $text[$pos + 1] === '^') {
                    if (preg_match(self::PATTERN_FOOTNOTE_REF, $text, $m, 0, $pos)) {
                        $label = $m[1];
                        if (isset($this->footnoteDefinitions[$label]) && is_array($this->footnoteDefinitions[$label])) {
                            // Assign number on first encounter; increment occurrence counter.
                            if (!isset($this->footnoteDefinitions[$label]['number'])) {
                                $nextNum = ($this->footnoteDefinitions['__next_number__'] ?? 0) + 1;
                                $this->footnoteDefinitions[$label]['number']      = $nextNum;
                                $this->footnoteDefinitions[$label]['occurrences'] = 0;
                                $this->footnoteDefinitions['__next_number__']     = $nextNum;
                            }
                            $this->footnoteDefinitions[$label]['occurrences']++;
                            $occurrence = $this->footnoteDefinitions[$label]['occurrences'];
                            $tokens = $this->flushBuffer($buffer, $tokens);
                            $buffer = '';
                            $tokens[] = new FootnoteRefNode(
                                label:      $label,
                                number:     $this->footnoteDefinitions[$label]['number'],
                                occurrence: $occurrence,
                            );
                            $pos += strlen($m[0]);
                            continue;
                        }
                        // Label not in definitions map — fall through to literal text.
                    }
                    // No match or undefined — fall through.
                }

                // 1a. Angle-bracket URL form: [text](<url> or <url "title">) — checked first.
                if (preg_match(self::PATTERN_LINK_ANGLE, $text, $m, 0, $pos)) {
                    $tokens = $this->flushBuffer($buffer, $tokens);
                    $buffer = '';
                    $href = stripslashes($m[2]);
                    if ($this->isSafeAngleBracketUrl($href)) {
                        $rawTitle = ($m[3] ?? '') !== '' ? $m[3] : (($m[4] ?? '') !== '' ? $m[4] : (($m[5] ?? '') !== '' ? $m[5] : null));
                        $tokens[] = new LinkNode(
                            href: $href,
                            children: $this->scan($m[1], $depth + 1),
                            title: $rawTitle !== null ? stripslashes($rawTitle) : null,
                        );
                    } else {
                        $buffer .= $m[0]; // Unsafe URL: render as literal text (XSS prevention)
                    }
                    $pos += strlen($m[0]);
                    continue;
                }

                // 1b. Inline link — highest priority (CommonMark spec §6.3)
                if (preg_match(self::PATTERN_LINK, $text, $m, 0, $pos)) {
                    $tokens = $this->flushBuffer($buffer, $tokens);
                    $buffer = '';
                    $href = $m[2];
                    if ($this->isSafeUrl($href)) {
                        $rawTitle = ($m[3] ?? '') !== '' ? $m[3] : (($m[4] ?? '') !== '' ? $m[4] : (($m[5] ?? '') !== '' ? $m[5] : null));
                        $tokens[] = new LinkNode(
                            href: $href,
                            children: $this->scan($m[1], $depth + 1),
                            title: $rawTitle !== null ? stripslashes($rawTitle) : null,
                        );
                    } else {
                        $buffer .= $m[0]; // Unsafe URL: render as literal text (XSS prevention)
                    }
                    $pos += strlen($m[0]);
                    continue;
                }

                // 2. Reference link: [text][ref] or collapsed [text][]
                if (preg_match(self::PATTERN_REF_LINK, $text, $m, 0, $pos)) {
                    $tokens = $this->flushBuffer($buffer, $tokens);
                    $buffer = '';
                    $lookupKey = mb_strtolower($m[2] !== '' ? $m[2] : $m[1], 'UTF-8');
                    if (isset($this->refs[$lookupKey])) {
                        $def = $this->refs[$lookupKey];
                        if ($this->isSafeUrl($def['href'])) {
                            $tokens[] = new LinkNode(
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
                            $tokens = $this->flushBuffer($buffer, $tokens);
                            $buffer = '';
                            if ($this->isSafeUrl($def['href'])) {
                                $tokens[] = new LinkNode(
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
                        $tokens = $this->flushBuffer($buffer, $tokens);
                        $buffer = '';
                        $tokens[] = new StrikethroughNode($this->scan($inner, $depth + 1));
                        $pos = $closePos + 2;
                        continue;
                    }
                }
                $buffer .= '~~';
                $pos += 2;
                continue;
            }

            // ── Emphasis / Strong: * and _ runs (CommonMark §6.2 + Appendix A) ──
            if ($char === '*' || $char === '_') {
                $tokens = $this->flushBuffer($buffer, $tokens);
                $buffer = '';
                $runLen = 0;
                while (($pos + $runLen) < $len && $text[$pos + $runLen] === $char) {
                    $runLen++;
                }
                ['canOpen' => $canOpen, 'canClose' => $canClose] =
                    FlankingComputer::compute($text, $pos, $runLen, $char);
                $tokens[] = new DelimiterRun($char, $runLen, $canOpen, $canClose);
                $pos += $runLen;
                continue;
            }

            // ── Raw inline HTML / autolinks: <...> (§6.6 and §6.9) ─────────
            if ($char === '<') {
                // 1. URL autolink — MUST run before PATTERN_RAW_HTML_INLINE (see constant comment).
                if (preg_match(self::PATTERN_AUTOLINK_URL, $text, $m, 0, $pos)) {
                    $tokens = $this->flushBuffer($buffer, $tokens);
                    $buffer = '';
                    $tokens[] = new AutolinkNode($m[1], false);
                    $pos += strlen($m[0]);
                    continue;
                }
                // 2. Email autolink.
                if (preg_match(self::PATTERN_AUTOLINK_EMAIL, $text, $m, 0, $pos)) {
                    $tokens = $this->flushBuffer($buffer, $tokens);
                    $buffer = '';
                    $tokens[] = new AutolinkNode($m[1], true);
                    $pos += strlen($m[0]);
                    continue;
                }
                // 3. Raw inline HTML (§6.6) — existing logic unchanged.
                if (preg_match(self::PATTERN_RAW_HTML_INLINE, $text, $m, 0, $pos)) {
                    $tokens = $this->flushBuffer($buffer, $tokens);
                    $buffer = '';
                    $tokens[] = new RawHtmlInlineNode($m[0]);
                    $pos += strlen($m[0]);
                    continue;
                }
                // No match (e.g. <3, < p>) — fall through to catch-all.
            }

            $buffer .= $char;
            $pos++;
        }

        $tokens = $this->flushBuffer($buffer, $tokens);
        return (new DelimiterStack())->resolve($tokens);
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
     * @param list<DelimiterRun|InlineNodeInterface> $tokens
     */
    private function parseBackslashEscape(
        string $text,
        int    $pos,
        int    $len,
        string &$buffer,
        array  &$tokens,
    ): int {
        if ($pos + 1 >= $len) {
            $buffer .= '\\';
            return $pos + 1;
        }

        $next = $text[$pos + 1];

        if (str_contains(self::ESCAPABLE_CHARS, $next)) {
            $tokens  = $this->flushBuffer($buffer, $tokens);
            $buffer  = '';
            $tokens[] = new TextNode($next);
            return $pos + 2;
        }

        $buffer .= '\\';
        return $pos + 1;
    }

    /**
     * @param list<DelimiterRun|InlineNodeInterface> $tokens
     * @return list<DelimiterRun|InlineNodeInterface>
     */
    private function flushBuffer(string $buffer, array $tokens): array
    {
        if ($buffer !== '') {
            $tokens[] = new TextNode($buffer);
        }
        return $tokens;
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

    /**
     * Safety check for angle-bracket-delimited URLs.
     *
     * Spaces are intentionally allowed (that is the point of angle-bracket URLs).
     * All other C0/C1 control characters are rejected — browsers strip CR and TAB
     * from href values, which would allow javascript: scheme bypass if permitted.
     */
    private function isSafeAngleBracketUrl(string $url): bool
    {
        // Reject control chars except space (0x20). rawurldecode first to catch %0d/%09 etc.
        if (preg_match('/[\x00-\x1F\x7F]/', rawurldecode($url))) {
            return false;
        }
        // Reject protocol-relative URLs.
        if (str_starts_with($url, '//') || str_starts_with($url, '\\')) {
            return false;
        }
        // For scheme extraction, encode spaces so parse_url() doesn't choke.
        $urlForParse = str_replace(' ', '%20', $url);
        $parts = parse_url($urlForParse);
        if ($parts === false) {
            return false;
        }
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        return in_array($scheme, self::SAFE_SCHEMES, true);
    }
}
