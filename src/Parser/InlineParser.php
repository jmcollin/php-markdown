<?php

declare(strict_types=1);

namespace PhpMarkdown\Parser;

use PhpMarkdown\Node\Inline\AutolinkNode;
use PhpMarkdown\Node\Inline\CodeNode;
use PhpMarkdown\Node\Inline\EmphasisNode;
use PhpMarkdown\Node\Inline\FootnoteRefNode;
use PhpMarkdown\Node\Inline\HardBreakNode;
use PhpMarkdown\Node\Inline\HtmlEntityNode;
use PhpMarkdown\Node\Inline\ImageNode;
use PhpMarkdown\Node\Inline\LinkNode;
use PhpMarkdown\Node\Inline\RawHtmlInlineNode;
use PhpMarkdown\Node\Inline\SoftBreakNode;
use PhpMarkdown\Node\Inline\StrikethroughNode;
use PhpMarkdown\Node\Inline\StrongNode;
use PhpMarkdown\Node\Inline\TextNode;
use PhpMarkdown\Node\InlineNodeInterface;
use PhpMarkdown\Sanitizer\UrlValidator;

/**
 * Parses inline Markdown syntax into a sequence of InlineNode objects.
 *
 * Stateless recursive scanner: handles nested emphasis/strong correctly.
 */
final class InlineParser
{
    /** Maximum nesting depth for recursive inline parsing (prevents stack overflow). */
    private const MAX_DEPTH = 64;

    /** Longest link label, in bytes (CommonMark §4.7 allows at most 999 characters). */
    private const MAX_LABEL_LENGTH = 999;

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

    /** Bytes that may start an inline construct in scan(); every other byte is plain text. */
    private const SPECIAL_CHARS = "\\`&![]~*_<";

    /** Bytes a raw link destination scan must stop on: '\\', parentheses, space and controls. */
    private const RAW_DESTINATION_STOP = "\\()\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x20\x7F";

    // Inline patterns are matched at a given offset with the A (anchored) modifier and
    // (*NO_START_OPT). Without the latter PCRE first checks that a required character
    // ('>', '@', ';', ']') exists somewhere after the offset: a scan of the rest of the
    // text on every call, which made input like "< a < a …" quadratic.
    // CommonMark §6.9 — autolinks: <scheme:path> and <email>.
    // Scheme: letter followed by 1–31 chars of [letter digit + - .], then colon.
    // Path: any char except NUL, space, <, >.
    // SAFETY: These checks MUST run before PATTERN_RAW_HTML_INLINE in scan() — the raw-HTML
    // alternative [a-zA-Z][^>]*> would swallow <http://example.com> as an opening tag.
    private const PATTERN_AUTOLINK_URL =
        '/(*NO_START_OPT)<([a-zA-Z][a-zA-Z0-9+\-.]{1,31}:[^\x00-\x20<>]*)>/A';

    // Email autolink: <local@domain> with CommonMark §6.9 local-part and domain rules.
    private const PATTERN_AUTOLINK_EMAIL =
        "/(*NO_START_OPT)<([a-zA-Z0-9.!#\$%&'*+\/=?^_{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*)>/A";

    // CommonMark §6.6 — inline HTML open or closing tag, with the spec's tag grammar
    // (tag name, attributes, optional value). Possessive quantifiers and bounded quoted
    // values keep a failed match O(1): the former "[^>]*" consumed the rest of the text
    // on every "<x" without a '>', making such input quadratic. Comments are handled in
    // scan() with a memoised search for "-->".
    private const PATTERN_RAW_HTML_INLINE =
        '/(*NO_START_OPT)<(?:\/[A-Za-z][A-Za-z0-9-]*+\s*+>'
        . '|[A-Za-z][A-Za-z0-9-]*+'
        . '(?:\s++[A-Za-z_:][A-Za-z0-9_.:-]*+(?:\s*+=\s*+(?:[^\s"\'=<>`]++|\'[^\']{0,1024}+\'|"[^"]{0,1024}+"))?+)*+'
        . '\s*+\/?>)/A';

    /**
     * Matches a well-formed HTML entity at the current position:
     *   - numeric decimal:  &#[0-9]{1,7};
     *   - numeric hex:      &#[xX][0-9A-Fa-f]{1,6};
     *   - named:            &[A-Za-z][A-Za-z0-9]{1,31};
     */
    private const PATTERN_ENTITY = '/(*NO_START_OPT)&(?:#[0-9]{1,7}|#[xX][0-9A-Fa-f]{1,6}|[A-Za-z][A-Za-z0-9]{1,31});/A';

    /** Matches an inline footnote reference: [^label] anchored at current position. */
    private const PATTERN_FOOTNOTE_REF = '/(*NO_START_OPT)\[\^([A-Za-z0-9_-]{1,50})\]/A';

    /** @var array<string, array{href: string, title: ?string}> */
    private array $refs = [];

    /** @var array<string, array{body: string, number?: int, occurrences?: int}> */
    private array $footnoteDefinitions = [];

    private int $footnoteNextNumber = 0;

    /**
     * @param array<string, array{href: string, title: ?string}> $refs
     * @param array<string, array{body: string, number?: int, occurrences?: int}> $footnoteDefinitions
     *        Keyed by raw (case-sensitive) label. Mutated during scan():
     *        - 'number' int is assigned on first reference encounter.
     *        - 'occurrences' int is incremented on every encounter.
     * @param ?int $footnoteCounter Highest footnote number assigned so far, updated on return.
     *        Callers parsing many inline runs keep it between calls: when null it is recomputed
     *        from $footnoteDefinitions, which per run would be quadratic in a whole document.
     * @psalm-suppress UnusedParam
     * @return InlineNodeInterface[]
     */
    public function parse(string $text, array $refs = [], array &$footnoteDefinitions = [], ?int &$footnoteCounter = null): array
    {
        $this->refs               = $refs;
        $this->footnoteDefinitions = &$footnoteDefinitions;
        // Restore counter to max already-assigned number so each parse() call continues
        // from where the previous one left off (footnotes span multiple paragraphs).
        $this->footnoteNextNumber = $footnoteCounter ?? 0;
        if ($footnoteCounter === null) {
            foreach ($this->footnoteDefinitions as $def) {
                if (isset($def['number']) && $def['number'] > $this->footnoteNextNumber) {
                    $this->footnoteNextNumber = $def['number'];
                }
            }
        }
        try {
            return $this->scan($text, 0);
        } finally {
            $footnoteCounter = $this->footnoteNextNumber;
            $this->refs = [];
            // Unset the reference to the caller's array before re-initialising the property,
            // so the caller's array retains the mutations (assigned numbers, occurrence counts).
            unset($this->footnoteDefinitions);
            $this->footnoteDefinitions  = [];
            $this->footnoteNextNumber   = 0;
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

        // Plain text, the most common inline run, has nothing to scan or resolve.
        $len = strlen($text);
        if (strcspn($text, self::SPECIAL_CHARS) === $len) {
            return $len > 0 ? [new TextNode($text)] : [];
        }

        /** @var list<DelimiterRun|InlineNodeInterface> $tokens */
        $tokens = [];
        $pos = 0;
        $buffer = '';
        // Bracket stack as two int lists (memory: one entry per '[' of the input):
        // $brackets = token index * 2 + (1 if '![' else 0), $bracketStarts = label start.
        /** @var list<int> $brackets */
        $brackets = [];
        /** @var list<int> $bracketStarts */
        $bracketStarts = [];
        // Openers below this stack index are inactive: no links inside links (§6.3).
        $inactiveBelow = 0;
        // Memoised search for the end of an HTML comment: first "-->" at or after
        // $commentSearchFrom (false: none until the end of the text).
        $commentSearchFrom = PHP_INT_MAX;
        $commentEnd        = false;
        // Built on the first code span search that fails, see backtickRuns().
        /** @var ?list<array{int, int}> $backtickRuns */
        $backtickRuns = null;
        $backtickRun  = 0;

        while ($pos < $len) {
            // Copy a run of ordinary bytes in one call: only SPECIAL_CHARS start a construct.
            $run = strcspn($text, self::SPECIAL_CHARS, $pos);
            if ($run > 0) {
                $buffer .= substr($text, $pos, $run);
                $pos    += $run;
                continue;
            }

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
                // Once a search has failed, later ones consult the run table first: a failed
                // strpos reads the whole rest of the text, which adds up to quadratic when
                // many backtick runs have no closer.
                if ($backtickRuns !== null) {
                    while (isset($backtickRuns[$backtickRun]) && $backtickRuns[$backtickRun][0] < $tmp) {
                        $backtickRun++;
                    }
                }
                $closePos = $backtickRuns !== null && ($backtickRuns[$backtickRun][1] ?? 0) < $btCount
                    ? false
                    : strpos($text, str_repeat('`', $btCount), $tmp);
                if ($closePos === false) {
                    $backtickRuns ??= self::backtickRuns($text);
                }
                if ($closePos !== false) {
                    $this->flushBuffer($buffer, $tokens);
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

                // Determine whether this is a numeric or named entity and compute $decodedSafe:
                // the Unicode character(s) that the entity represents, HTML-escaped as needed
                // so the renderer can output the value verbatim in an HTML text node.
                if ($raw[1] === '#') {
                    // Numeric entity — extract the codepoint.
                    $inner = substr($raw, 2, -1); // strip leading '&#' and trailing ';'
                    if ($inner[0] === 'x' || $inner[0] === 'X') {
                        $codepoint = hexdec(substr($inner, 1));
                    } else {
                        $codepoint = (int) $inner;
                    }

                    // CommonMark §2.5 / HTML5 §8.1.4 — three buckets:
                    if ($codepoint === 0
                        || ($codepoint >= 0xD800 && $codepoint <= 0xDFFF)
                    ) {
                        // NUL and surrogates → replacement character U+FFFD.
                        $decodedSafe = "\u{FFFD}";
                    } elseif (
                        $codepoint > 0x10FFFF                       // beyond Unicode range
                        || ($codepoint >= 0x0001 && $codepoint <= 0x001F
                            && $codepoint !== 0x0009                // TAB
                            && $codepoint !== 0x000A                // LF
                            && $codepoint !== 0x000D)               // CR
                        || $codepoint === 0x007F                    // DEL
                        || ($codepoint >= 0xFDD0 && $codepoint <= 0xFDEF) // non-characters
                        || $codepoint === 0xFFFE
                        || $codepoint === 0xFFFF
                    ) {
                        // Invalid / non-character codepoint → pass raw entity through as text.
                        $buffer .= $raw;
                        $pos    += strlen($raw);
                        continue;
                    } else {
                        // Valid codepoint → decode to UTF-8 char, then HTML-escape if needed.
                        $decodedSafe = htmlspecialchars(
                            mb_chr($codepoint, 'UTF-8'),
                            ENT_HTML5,
                            'UTF-8',
                        );
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
                    // Decode succeeded: HTML-escape the resulting character(s) for safe output.
                    $decodedSafe = htmlspecialchars($decoded, ENT_HTML5, 'UTF-8');
                }

                // Valid entity: flush pending buffer, emit decoded node, advance.
                $this->flushBuffer($buffer, $tokens);
                $tokens[] = new HtmlEntityNode($decodedSafe);
                $pos    += strlen($raw);
                continue;
            }

            // ── Link / image openers: '[' and '![' (CommonMark §6.3, "look for link or image").
            // Openers are pushed as placeholder TextNodes; a later ']' turns the tokens after
            // a matching opener into a LinkNode/ImageNode, so link text is parsed only once
            // and nested brackets, code spans and autolinks take their natural precedence.
            if ($char === '!' && ($pos + 1) < $len && $text[$pos + 1] === '[') {
                $this->flushBuffer($buffer, $tokens);
                $tokens[]   = self::bracketPlaceholder('![');
                $brackets[]      = (count($tokens) - 1) * 2 + 1;
                $bracketStarts[] = $pos + 2;
                $pos += 2;
                continue;
            }

            if ($char === '[') {
                // ── Footnote reference: [^label] ─────────────────────────────────
                // Gate on $text[$pos+1] === '^' to avoid regex overhead on every [.
                if (isset($text[$pos + 1]) && $text[$pos + 1] === '^') {
                    if (preg_match(self::PATTERN_FOOTNOTE_REF, $text, $m, 0, $pos)) {
                        $label = $m[1];
                        if (isset($this->footnoteDefinitions[$label])) {
                            // Assign number on first encounter; increment occurrence counter.
                            if (!isset($this->footnoteDefinitions[$label]['number'])) {
                                $nextNum = ++$this->footnoteNextNumber;
                                $this->footnoteDefinitions[$label]['number']      = $nextNum;
                                $this->footnoteDefinitions[$label]['occurrences'] = 0;
                            }
                            /** @psalm-suppress PossiblyUndefinedArrayOffset */
                            $this->footnoteDefinitions[$label]['occurrences']++;
                            /** @psalm-suppress PossiblyUndefinedArrayOffset */
                            $defNumber  = $this->footnoteDefinitions[$label]['number'];
                            $occurrence = $this->footnoteDefinitions[$label]['occurrences'];
                            $this->flushBuffer($buffer, $tokens);
                            $tokens[] = new FootnoteRefNode(
                                number:     $defNumber,
                                occurrence: $occurrence,
                            );
                            $pos += strlen($m[0]);
                            continue;
                        }
                        // Label not in definitions map — fall through to literal text.
                    }
                    // No match or undefined — fall through.
                }

                $this->flushBuffer($buffer, $tokens);
                $tokens[]   = self::bracketPlaceholder('[');
                $brackets[]      = (count($tokens) - 1) * 2;
                $bracketStarts[] = $pos + 1;
                $pos++;
                continue;
            }

            if ($char === ']' && $brackets !== []) {
                $pos = $this->closeBracket($text, $pos, $buffer, $tokens, $brackets, $bracketStarts, $inactiveBelow);
                continue;
            }

            // ── Strikethrough: ~~text~~ ──────────────────────────────────────
            if ($char === '~' && isset($text[$pos + 1]) && $text[$pos + 1] === '~') {
                $closePos = strpos($text, '~~', $pos + 2);
                if ($closePos !== false) {
                    $inner = substr($text, $pos + 2, $closePos - $pos - 2);
                    if ($inner !== '') {
                        $this->flushBuffer($buffer, $tokens);
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
                $this->flushBuffer($buffer, $tokens);
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
                    if (UrlValidator::hasScriptScheme($m[1])) {
                        // <javascript:…>, <data:…>, <vbscript:…>: literal text (XSS prevention).
                        $buffer .= $m[0];
                        $pos    += strlen($m[0]);
                        continue;
                    }
                    $this->flushBuffer($buffer, $tokens);
                    $tokens[] = new AutolinkNode($m[1], false);
                    $pos += strlen($m[0]);
                    continue;
                }
                // 2. Email autolink.
                if (preg_match(self::PATTERN_AUTOLINK_EMAIL, $text, $m, 0, $pos)) {
                    $this->flushBuffer($buffer, $tokens);
                    $tokens[] = new AutolinkNode($m[1], true);
                    $pos += strlen($m[0]);
                    continue;
                }
                // 3. HTML comment (§6.6): "<!-->", "<!--->" or "<!--" … "-->". The position of
                //    the next "-->" is memoised so repeated "<!--" stays linear.
                if (substr_compare($text, '<!--', $pos, 4) === 0) {
                    if ($commentSearchFrom > $pos || ($commentEnd !== false && $commentEnd < $pos + 2)) {
                        $commentSearchFrom = $pos;
                        $commentEnd        = strpos($text, '-->', $pos + 2);
                    }
                    if ($commentEnd !== false) {
                        $end = substr_compare($text, '<!-->', $pos, 5) === 0 ? $pos + 5
                            : (substr_compare($text, '<!--->', $pos, 6) === 0 ? $pos + 6 : $commentEnd + 3);
                        $this->flushBuffer($buffer, $tokens);
                        $tokens[] = new RawHtmlInlineNode(substr($text, $pos, $end - $pos));
                        $pos = $end;
                        continue;
                    }
                }
                // 4. Raw inline HTML tag (§6.6).
                if (preg_match(self::PATTERN_RAW_HTML_INLINE, $text, $m, 0, $pos)) {
                    $this->flushBuffer($buffer, $tokens);
                    $tokens[] = new RawHtmlInlineNode($m[0]);
                    $pos += strlen($m[0]);
                    continue;
                }
                // No match (e.g. <3, < p>) — fall through to catch-all.
            }

            $buffer .= $char;
            $pos++;
        }

        $this->flushBuffer($buffer, $tokens);
        return (new DelimiterStack())->resolve($tokens);
    }

    /**
     * Every backtick run of $text, in order: [start offset, longest run from this one on].
     * A code span closer of length n exists after an offset if and only if the first run
     * after it has a "longest" of at least n.
     *
     * @return list<array{int, int}>
     */
    private static function backtickRuns(string $text): array
    {
        $runs = [];
        $len  = strlen($text);
        $pos  = strcspn($text, '`');
        while ($pos < $len) {
            $run    = strspn($text, '`', $pos);
            $runs[] = [$pos, $run];
            $pos   += $run;
            $pos   += strcspn($text, '`', $pos);
        }
        $longest = 0;
        for ($i = count($runs) - 1; $i >= 0; $i--) {
            $longest     = max($longest, $runs[$i][1]);
            $runs[$i][1] = $longest;
        }
        return $runs;
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
            $this->flushBuffer($buffer, $tokens);
            $tokens[] = new TextNode($next);
            return $pos + 2;
        }

        $buffer .= '\\';
        return $pos + 1;
    }

    /**
     * Append the pending text buffer as a TextNode and clear it.
     * Both arguments are taken by reference: passing $tokens by value and returning it
     * would copy the whole array on every flush, making inline parsing quadratic.
     *
     * @param list<DelimiterRun|InlineNodeInterface> $tokens
     */
    private function flushBuffer(string &$buffer, array &$tokens): void
    {
        if ($buffer !== '') {
            $tokens[] = new TextNode($buffer);
            $buffer   = '';
        }
    }

    /**
     * A '[' or '![' opener in the token list. It is an inert delimiter run (it can neither
     * open nor close emphasis), so when no link forms DelimiterStack turns it back into
     * literal text merged with its neighbours.
     */
    private static function bracketPlaceholder(string $literal): DelimiterRun
    {
        return new DelimiterRun($literal, 1, false, false);
    }

    /**
     * Handle ']' when an opener is on the bracket stack (CommonMark §6.3).
     *
     * @param list<DelimiterRun|InlineNodeInterface>      $tokens
     * @param list<int> $brackets      token index * 2 + (1 for '![')
     * @param list<int> $bracketStarts label start offsets, parallel to $brackets
     * @return int new cursor position
     */
    private function closeBracket(
        string $text,
        int    $pos,
        string &$buffer,
        array  &$tokens,
        array  &$brackets,
        array  &$bracketStarts,
        int    &$inactiveBelow,
    ): int {
        $packed    = (int) array_pop($brackets);
        $start     = (int) array_pop($bracketStarts);
        $openerIdx = $packed >> 1;
        $isImage   = ($packed & 1) === 1;
        // After a link forms, earlier '[' openers are inactive; '![' openers stay active.
        $active = $isImage || count($brackets) >= $inactiveBelow;
        $inactiveBelow = min($inactiveBelow, count($brackets));

        $match = $active
            ? $this->matchLinkTail($text, $pos + 1, $start)
            : null;
        if ($match === null || !UrlValidator::isSafe($match['href'])) {
            // Not a link (or an unsafe URL — XSS prevention): ']' is literal text and the
            // opener placeholder stays as a literal '[' / '!['.
            $buffer .= ']';
            return $pos + 1;
        }

        $this->flushBuffer($buffer, $tokens);
        $children = (new DelimiterStack())->resolve(array_slice($tokens, $openerIdx + 1));
        // Drop the opener and the link text from the tail. array_pop is O(removed);
        // array_splice would rebuild the whole token list on every link.
        while (count($tokens) > $openerIdx) {
            array_pop($tokens);
        }

        if ($isImage) {
            $tokens[] = new ImageNode(src: $match['href'], alt: $this->plainText($children), title: $match['title']);
        } else {
            // Footnote references are links themselves: move them after the link rather
            // than nesting <a> inside <a>.
            $linkChildren = [];
            $footnotes    = [];
            foreach ($children as $child) {
                if ($child instanceof FootnoteRefNode) {
                    $footnotes[] = $child;
                } else {
                    $linkChildren[] = $child;
                }
            }
            $tokens[] = new LinkNode(href: $match['href'], children: $linkChildren, title: $match['title']);
            array_push($tokens, ...$footnotes);
            // No links inside links: every '[' opener still on the stack becomes inactive.
            $inactiveBelow = count($brackets);
        }

        return $match['end'];
    }

    /**
     * Try, at $pos (just after ']'), an inline destination "(…)", then a full
     * "[label]", collapsed "[]" or shortcut reference. The opener's label runs from
     * $labelStart to the ']' at $pos - 1; it is only copied once known to be short enough,
     * as nested brackets would otherwise copy most of the text at each ']'.
     *
     * @return array{href: string, title: ?string, end: int}|null
     */
    private function matchLinkTail(string $text, int $pos, int $labelStart): ?array
    {
        if (($text[$pos] ?? '') === '(') {
            $inline = $this->parseInlineDestination($text, $pos + 1);
            if ($inline !== null) {
                return $inline;
            }
        }

        if ($this->refs === []) {
            return null;
        }

        $end = $pos;
        if (($text[$pos] ?? '') === '[') {
            $label = $this->scanLinkLabel($text, $pos);
            if ($label !== null && trim($label[0], " \t\n") !== '') {
                // Full reference: only the explicit label is looked up.
                return $this->lookupReference($label[0], $label[1]);
            }
            if ($label !== null && $label[0] === '') {
                $end = $label[1]; // collapsed "[]"
            }
        }

        $labelLength = $pos - 1 - $labelStart;
        if ($labelLength > self::MAX_LABEL_LENGTH) {
            return null;
        }
        $openerLabel = substr($text, $labelStart, $labelLength);
        if (!$this->isValidLinkLabel($openerLabel)) {
            return null;
        }
        return $this->lookupReference($openerLabel, $end);
    }

    /** @return array{href: string, title: ?string, end: int}|null */
    private function lookupReference(string $label, int $end): ?array
    {
        $def = $this->refs[self::normalizeLabel($label)] ?? null;
        if ($def === null) {
            return null;
        }
        return [
            'href'  => $this->decodeLinkText($def['href']),
            'title' => $def['title'] !== null ? $this->decodeLinkText($def['title']) : null,
            'end'   => $end,
        ];
    }

    /**
     * Parse "destination title?)" after the '(' of an inline link (§6.3).
     *
     * @return array{href: string, title: ?string, end: int}|null
     */
    private function parseInlineDestination(string $text, int $pos): ?array
    {
        $len = strlen($text);
        $pos = $this->skipLinkWhitespace($text, $pos);
        if ($pos === null) {
            return null;
        }
        if (($text[$pos] ?? '') === ')') {
            return ['href' => '', 'title' => null, 'end' => $pos + 1];
        }

        if (($text[$pos] ?? '') === '<') {
            // <…>: no line endings, no unescaped '<' or '>'.
            $start = $pos + 1;
            for ($i = $start; $i < $len; $i++) {
                $i += strcspn($text, "\\<>\n", $i); // skip ordinary bytes in one call
                if ($i >= $len) {
                    break;
                }
                $c = $text[$i];
                if ($c === '\\' && $i + 1 < $len) {
                    $i++;
                    continue;
                }
                if ($c === '>') {
                    break;
                }
                if ($c === '<' || $c === "\n") {
                    return null;
                }
            }
            if ($i >= $len) {
                return null;
            }
            $dest = substr($text, $start, $i - $start);
            $pos  = $i + 1;
        } else {
            // Raw destination: no spaces or controls, balanced (unescaped) parentheses.
            $start = $pos;
            $depth = 0;
            for ($i = $pos; $i < $len; $i++) {
                $i += strcspn($text, self::RAW_DESTINATION_STOP, $i); // skip ordinary bytes in one call
                if ($i >= $len) {
                    break;
                }
                $c = $text[$i];
                if ($c === '\\' && $i + 1 < $len && str_contains(self::ESCAPABLE_CHARS, $text[$i + 1])) {
                    $i++;
                    continue;
                }
                if ($c === '(') {
                    if (++$depth > 32) {
                        return null;
                    }
                } elseif ($c === ')') {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                } elseif (ord($c) <= 0x20 || ord($c) === 0x7F) {
                    break;
                }
            }
            if ($depth !== 0 || $i === $start) {
                return null;
            }
            $dest = substr($text, $start, $i - $start);
            $pos  = $i;
        }

        $title  = null;
        $afterWs = $this->skipLinkWhitespace($text, $pos);
        if ($afterWs === null) {
            return null;
        }
        $opener = $text[$afterWs] ?? '';
        if ($afterWs > $pos && ($opener === '"' || $opener === "'" || $opener === '(')) {
            $closer = $opener === '(' ? ')' : $opener;
            $stop   = '\\' . $closer . ($opener === '(' ? '(' : '');
            for ($i = $afterWs + 1; $i < $len; $i++) {
                $i += strcspn($text, $stop, $i); // skip ordinary bytes in one call
                if ($i >= $len) {
                    break;
                }
                $c = $text[$i];
                if ($c === '\\' && $i + 1 < $len) {
                    $i++;
                    continue;
                }
                if ($c === $closer) {
                    break;
                }
                if ($opener === '(' && $c === '(') {
                    return null;
                }
            }
            if ($i >= $len) {
                return null;
            }
            $rawTitle = substr($text, $afterWs + 1, $i - $afterWs - 1);
            if (preg_match('/\n[ \t]*\n/', $rawTitle)) {
                return null; // a title cannot contain a blank line
            }
            $title   = $this->decodeLinkText($rawTitle);
            $afterWs = $this->skipLinkWhitespace($text, $i + 1);
            if ($afterWs === null) {
                return null;
            }
        }

        if (($text[$afterWs] ?? '') !== ')') {
            return null;
        }
        return ['href' => $this->decodeLinkText($dest), 'title' => $title, 'end' => $afterWs + 1];
    }

    /** Skip spaces/tabs and at most one line ending; null if a blank line is crossed. */
    private function skipLinkWhitespace(string $text, int $pos): ?int
    {
        $len      = strlen($text);
        $newlines = 0;
        while ($pos < $len && ($text[$pos] === ' ' || $text[$pos] === "\t" || $text[$pos] === "\n")) {
            if ($text[$pos] === "\n" && ++$newlines > 1) {
                return null;
            }
            $pos++;
        }
        return $pos;
    }

    /**
     * Scan a link label "[…]" starting at $pos: at most 999 chars, no unescaped brackets.
     *
     * @return array{string, int}|null [label content, position after ']']
     */
    private function scanLinkLabel(string $text, int $pos): ?array
    {
        $len = strlen($text);
        for ($i = $pos + 1; $i < $len && $i - $pos <= 1000; $i++) {
            $c = $text[$i];
            if ($c === '\\' && $i + 1 < $len) {
                $i++;
                continue;
            }
            if ($c === '[') {
                return null;
            }
            if ($c === ']') {
                return [substr($text, $pos + 1, $i - $pos - 1), $i + 1];
            }
        }
        return null;
    }

    private function isValidLinkLabel(string $label): bool
    {
        return trim($label, " \t\n") !== ''
            && $this->scanLinkLabel('[' . $label . ']', 0) !== null;
    }

    /**
     * Link label matching key (CommonMark §4.7): Unicode case fold, inner whitespace
     * collapsed to one space, outer whitespace trimmed. Shared with Parser so that
     * definitions and references normalise identically.
     */
    public static function normalizeLabel(string $label): string
    {
        $collapsed = (string) preg_replace('/[ \t\n\r]+/', ' ', trim($label, " \t\n\r"));
        return mb_convert_case($collapsed, MB_CASE_FOLD, 'UTF-8');
    }

    /** Resolve backslash escapes and entity references in a destination or title. */
    private function decodeLinkText(string $raw): string
    {
        if (!str_contains($raw, '\\') && !str_contains($raw, '&')) {
            return $raw; // nothing to decode (the common case)
        }
        return (string) preg_replace_callback(
            '/\\\\([!-\/:-@\[-`{-~])|&(?:#[0-9]{1,7}|#[xX][0-9A-Fa-f]{1,6}|[A-Za-z][A-Za-z0-9]{1,31});/',
            static function (array $m): string {
                if (isset($m[1])) { // backslash escape (group absent for an entity match)
                    return $m[1];
                }
                if ($m[0] === '&#0;' || preg_match('/^&#[xX]?0+;$/', $m[0])) {
                    return "\u{FFFD}";
                }
                return html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            },
            $raw,
        );
    }

    /**
     * Plain-text rendering of inline nodes, used for image alt text (§6.4).
     *
     * @param InlineNodeInterface[] $nodes
     */
    private function plainText(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $node) {
            $out .= match (true) {
                $node instanceof TextNode          => $node->text,
                $node instanceof CodeNode          => $node->code,
                $node instanceof HtmlEntityNode    => html_entity_decode($node->entity, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                $node instanceof ImageNode         => $node->alt,
                $node instanceof AutolinkNode      => $node->url,
                $node instanceof RawHtmlInlineNode => $node->content,
                $node instanceof LinkNode,
                $node instanceof EmphasisNode,
                $node instanceof StrongNode,
                $node instanceof StrikethroughNode => $this->plainText($node->children),
                $node instanceof SoftBreakNode,
                $node instanceof HardBreakNode     => "\n",
                default                            => '',
            };
        }
        return $out;
    }
}
