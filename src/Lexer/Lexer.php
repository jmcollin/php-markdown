<?php

declare(strict_types=1);

namespace PhpMarkdown\Lexer;

/**
 * Tokenises a Markdown string into a flat sequence of Token objects.
 *
 * Stateless: every call to tokenize() is independent.
 * Single-pass O(n) on input length.
 */
final class Lexer
{
    private const PATTERN_HEADING           = '/^(#{1,6})\s+(.*)$/';
    private const PATTERN_FENCED_OPEN      = '/^ {0,3}([`~]{3,})\s*(\S*)\s*$/';
    private const PATTERN_BLOCKQUOTE       = '/^((?:>[ \t]*)++)(.*)/';
    /** Bullet list item: [1]=indent, [2]=marker, [3]=spaces after marker, [4]=content. */
    private const PATTERN_UNORDERED_LIST   = '/^( *)([-*+])([ \t]+)(\S.*)/';
    /** Ordered list item (CommonMark §5.2: 1–9 digits, '.' or ')'): [1]=indent, [2]=number, [3]=delimiter, [4]=spaces, [5]=content. */
    private const PATTERN_ORDERED_LIST     = '/^( *)(\d{1,9})([.)])([ \t]+)(\S.*)/';
    private const PATTERN_HORIZONTAL_RULE  = '/^[ \t]{0,3}([-*_])([ \t]*\1){2,}[ \t]*$/';
    private const PATTERN_LINK_DEFINITION  = '/^\[([^\]\[]+)\]:\s+(?:<((?:[^<>\\\\\n]|\\\\.)*)>|(\S+))(?:\s+(?:"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\'|\(((?:[^()\\\\]|\\\\.)*)\)))?$/';
    /** Matches a standalone title line (CommonMark §4.7 multiline link ref definition). */
    private const PATTERN_STANDALONE_TITLE = '/^(?:"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\'|\(((?:[^()\\\\]|\\\\.)*)\))\s*$/';
    private const PATTERN_TABLE_ROW        = '/^\|?[^|]+(?:\|[^|]+)+\|?$/';
    private const PATTERN_TABLE_SEPARATOR  = '/^\|?[ \t:|-]+(?:\|[ \t:|-]+)+\|?$/';
    private const PATTERN_SETEXT_H1        = '/^=+\s*$/';
    private const PATTERN_SETEXT_H2        = '/^-+\s*$/';
    private const PATTERN_COLUMNS_OPEN     = '/^:::\s*columns\s*$/i';
    private const PATTERN_COLUMNS_CLOSE    = '/^:::$/';
    private const PATTERN_COLUMNS_SEP      = '/^\|\|\|$/';
    private const PATTERN_FOOTNOTE_DEF    = '/^\[\^([A-Za-z0-9_-]{1,50})\]:\s+(.+)$/';

    /**
     * Block-level HTML tags that trigger HTML_BLOCK detection (CommonMark §4.6).
     * Sorted for readability; used to build PATTERN_HTML_BLOCK_TAG at construction time.
     */
    private const BLOCK_TAGS = [
        'address', 'article', 'aside', 'blockquote', 'canvas',
        'dd', 'details', 'dialog', 'div', 'dl', 'dt',
        'fieldset', 'figcaption', 'figure', 'footer', 'form',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'header', 'hgroup', 'hr', 'li', 'main', 'menu', 'nav', 'noscript',
        'ol', 'p', 'pre', 'section', 'summary',
        'table', 'ul',
    ];

    /**
     * Matches a line that opens a block-level HTML construct (CommonMark §4.6):
     *   - Open/close tag of a block-level element (up to 3 leading spaces)
     *   - HTML comment <!-- ... -->
     *   - <!DOCTYPE ...>
     *
     * Capture group 1: the stripped line content.
     */
    /** @var non-empty-string */
    private readonly string $patternHtmlBlockStart;

    public function __construct()
    {
        $tags = implode('|', self::BLOCK_TAGS);
        // Matches: optional 0-3 leading spaces, then an open OR close block tag,
        // OR an HTML comment opener, OR a <!DOCTYPE declaration.
        $this->patternHtmlBlockStart =
            '/^ {0,3}(?:<(?:' . $tags . ')(?:\s|>|\/|$)|<\/(?:' . $tags . ')(?:\s|>|$)|<!--.*?-->$|<!--.*$|<!DOCTYPE\s)/i';
    }

    /**
     * @return Token[]
     */
    public function tokenize(string $markdown): array
    {
        if ($markdown === '') {
            return [];
        }

        // Normalise all line-ending variants (\r\n, lone \r) to \n.
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $lines = explode("\n", $markdown);
        $tokens = [];

        $inFencedBlock = false;
        $fencedLanguage = '';
        $fencedLines = [];
        $fenceChar = '';
        $fenceLength = 0;
        $pendingLines = [];
        $pendingLinkDef = null; // LINK_DEFINITION token awaiting a possible next-line title

        $inHtmlBlock = false;
        $htmlLines = [];
        $htmlCommentPending = false; // true when inside <!-- ... --> spanning multiple lines

        $inColumnsBlock    = false;
        $columnsSepFound   = false;
        $columnsLeftLines  = [];
        $columnsRightLines = [];

        $inIndentedBlock = false;
        $indentedLines   = [];
        $pendingBlanks   = [];

        // Content columns of the open list items, outermost first (CommonMark §5.2):
        // an item is nested in the last open item whose content column it reaches.
        $listColumns   = [];
        $listOpenCache = [0, false];
        /** @var array<int, list<string>> $itemContinuations continuation lines, by LIST_ITEM token index */
        $itemContinuations = [];

        $inFootnoteBody     = false;
        $footnoteBodyLabel  = '';
        $footnoteBodyLines  = [];

        foreach ($lines as $raw) {
            $line     = rtrim($raw, "\r");
            $expanded = $this->expandTabs($line);

            // Collect lines for an in-progress HTML block.
            // The block ends on the first blank line (CommonMark §4.6 type 6/7).
            // Comment blocks (<!-- ... -->) end when --> is found.
            if ($inHtmlBlock) {
                if ($htmlCommentPending) {
                    // CommonMark §4.6 type 2: blank line before --> flushes without the blank.
                    if ($line === '' || ctype_space($line)) {
                        $tokens[] = new Token(TokenType::HTML_BLOCK, implode("\n", $htmlLines));
                        $tokens[] = new Token(TokenType::BLANK, '');
                        $inHtmlBlock = false;
                        $htmlLines = [];
                        $htmlCommentPending = false;
                        continue;
                    }
                    // CommonMark §4.6 type 2: block ends on the line containing -->.
                    if (str_contains($line, '-->')) {
                        $htmlLines[] = $line;
                        $tokens[] = new Token(TokenType::HTML_BLOCK, implode("\n", $htmlLines));
                        $inHtmlBlock = false;
                        $htmlLines = [];
                        $htmlCommentPending = false;
                        continue;
                    }
                    $htmlLines[] = $line;
                    continue;
                }

                if ($line === '' || ctype_space($line)) {
                    // Blank line terminates the HTML block (CommonMark §4.6 type 6).
                    $tokens[] = new Token(TokenType::HTML_BLOCK, implode("\n", $htmlLines));
                    $tokens[] = new Token(TokenType::BLANK, '');
                    $inHtmlBlock = false;
                    $htmlLines = [];
                    continue;
                }

                $htmlLines[] = $line;
                continue;
            }

            if ($inColumnsBlock) {
                if (preg_match(self::PATTERN_COLUMNS_CLOSE, $line)) {
                    foreach ($pendingLines as $pt) {
                        $tokens[] = $pt;
                    }
                    $pendingLines = [];
                    $tokens[] = new Token(
                        TokenType::COLUMNS_CONTAINER,
                        '',
                        [
                            'left_raw'  => implode("\n", $columnsLeftLines),
                            'right_raw' => implode("\n", $columnsRightLines),
                        ],
                    );
                    $inColumnsBlock    = false;
                    $columnsSepFound   = false;
                    $columnsLeftLines  = [];
                    $columnsRightLines = [];
                } elseif (preg_match(self::PATTERN_COLUMNS_SEP, $line) && !$columnsSepFound) {
                    $columnsSepFound = true;
                } elseif (!$columnsSepFound) {
                    $columnsLeftLines[] = $line;
                } else {
                    $columnsRightLines[] = $line;
                }
                continue;
            }

            if (preg_match(self::PATTERN_COLUMNS_OPEN, $line)) {
                foreach ($pendingLines as $pt) {
                    $tokens[] = $pt;
                }
                $pendingLines = [];
                $inColumnsBlock    = true;
                $columnsSepFound   = false;
                $columnsLeftLines  = [];
                $columnsRightLines = [];
                continue;
            }

            if ($inFencedBlock) {
                if (preg_match('/^ {0,3}' . preg_quote($fenceChar, '/') . '{' . $fenceLength . ',}\s*$/', $line)) {
                    foreach ($pendingLines as $pt) {
                        $tokens[] = $pt;
                    }
                    $pendingLines = [];
                    $tokens[] = new Token(
                        TokenType::FENCED_CODE,
                        implode("\n", $fencedLines),
                        ['language' => $fencedLanguage],
                    );
                    $inFencedBlock  = false;
                    $fencedLanguage = '';
                    $fencedLines    = [];
                    $fenceChar      = '';
                    $fenceLength    = 0;
                } else {
                    $fencedLines[] = $line;
                }
                continue;
            }

            if (preg_match(self::PATTERN_FENCED_OPEN, $line, $m)) {
                foreach ($pendingLines as $pt) {
                    $tokens[] = $pt;
                }
                $pendingLines = [];
                $inFencedBlock  = true;
                $fencedLanguage = $m[2];
                $fencedLines    = [];
                $fenceChar      = $m[1][0];
                $fenceLength    = strlen($m[1]);
                continue;
            }

            // HTML block detection (CommonMark §4.6).
            // Must run before setext/paragraph logic so that block-level HTML tags
            // are not consumed as paragraphs.
            if (preg_match($this->patternHtmlBlockStart, $line)) {
                foreach ($pendingLines as $pt) {
                    $tokens[] = $pt;
                }
                $pendingLines = [];
                // Detect whether this is an HTML comment opener.
                $isCommentStart = str_starts_with(ltrim($line), '<!--');
                $isCommentClosed = $isCommentStart && str_contains($line, '-->');

                // CommonMark §4.6 type 2: a self-contained comment (<!-- ... --> on one line)
                // ends immediately — no multi-line block state needed.
                if ($isCommentStart && $isCommentClosed) {
                    $tokens[] = new Token(TokenType::HTML_BLOCK, $line);
                    continue;
                }

                $inHtmlBlock = true;
                $htmlLines = [$line];
                $htmlCommentPending = $isCommentStart;
                continue;
            }

            // Footnote definition multi-line body continuation.
            // 4-space-indented lines are appended to the current footnote body.
            // Any other line flushes the accumulated body as a FOOTNOTE_DEFINITION token,
            // then falls through to process the current line normally.
            if ($inFootnoteBody) {
                if (preg_match('/^    (.+)/', $line, $fm)) {
                    $footnoteBodyLines[] = $fm[1];
                    continue;
                }
                // Non-continuation line: flush accumulated body.
                $tokens[] = new Token(
                    TokenType::FOOTNOTE_DEFINITION,
                    '',
                    ['label' => $footnoteBodyLabel, 'body' => implode(' ', $footnoteBodyLines)],
                );
                $inFootnoteBody    = false;
                $footnoteBodyLines = [];
                $footnoteBodyLabel = '';
                // Fall through to process the current line normally.
            }

            // Capture paragraph-active state before setext promotion may clear it.
            $hadPendingLines = $pendingLines !== [];

            // Setext heading detection: pending text lines followed by === or ---
            if ($pendingLines !== []) {
                if (preg_match(self::PATTERN_SETEXT_H1, $line)) {
                    $content = implode(' ', array_map(fn(Token $t) => $t->content, $pendingLines));
                    $tokens[] = new Token(TokenType::HEADING, $content, ['level' => 1]);
                    $pendingLines = [];
                    continue;
                }
                if (preg_match(self::PATTERN_SETEXT_H2, $line)) {
                    $content = implode(' ', array_map(fn(Token $t) => $t->content, $pendingLines));
                    $tokens[] = new Token(TokenType::HEADING, $content, ['level' => 2]);
                    $pendingLines = [];
                    continue;
                }
            }

            // Multiline link ref title (CommonMark §4.7): a LINK_DEFINITION with no title
            // may be followed by a standalone title line on the very next line.
            if ($pendingLinkDef !== null) {
                if ($line === '' || ctype_space($line)) {
                    // Blank line terminates the possibility of a continuation title.
                    $tokens[] = $pendingLinkDef;
                    $pendingLinkDef = null;
                    $tokens[] = new Token(TokenType::BLANK, '');
                    continue;
                }
                if (preg_match(self::PATTERN_STANDALONE_TITLE, $line, $tm)) {
                    // One of the three capture groups will be set.
                    $rawTitle = $tm[1] !== '' ? $tm[1] : ($tm[2] !== '' ? $tm[2] : ($tm[3] ?? ''));
                    // Strip control characters from title (U+0000–U+001F, U+007F)
                    $cleanTitle = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $rawTitle);
                    $meta = $pendingLinkDef->meta;
                    $meta['title'] = $cleanTitle !== '' ? $cleanTitle : null;
                    $tokens[] = new Token(TokenType::LINK_DEFINITION, $pendingLinkDef->content, $meta);
                    $pendingLinkDef = null;
                    continue;
                }
                // Current line is not a title line — flush the pending def and fall through.
                $tokens[] = $pendingLinkDef;
                $pendingLinkDef = null;
                // Fall through to process $line normally.
            }

            // Indented code block drain (continuation).
            if ($inIndentedBlock) {
                if (preg_match('/^    (.*)$/s', $expanded, $m)
                    && !preg_match(self::PATTERN_UNORDERED_LIST, $line)
                    && !preg_match(self::PATTERN_ORDERED_LIST, $line)
                ) {
                    $indentedLines = [...$indentedLines, ...$pendingBlanks, $this->stripLeadingColumns($line, 4)];
                    $pendingBlanks = [];
                    continue;
                }
                if ($line === '' || ctype_space($line)) {
                    $pendingBlanks[] = '';
                    continue;
                }
                // Non-indented, non-blank: close block.
                $tokens[] = new Token(
                    TokenType::INDENTED_CODE,
                    implode("\n", $indentedLines) . "\n",
                );
                $inIndentedBlock = false;
                $indentedLines   = [];
                $pendingBlanks   = [];
                // Fall through to process current line normally.
            }

            $listOpen = $this->lastNonBlankIsListItem($tokens, $listOpenCache);

            // List item continuation: paragraph text right after an item (no blank line in
            // between) continues that item's text, whether indented or lazy (§5.2, §5.1).
            $lastToken = end($tokens);
            if ($lastToken instanceof Token
                && $lastToken->type === TokenType::LIST_ITEM
                && $pendingLines === []
                && $line !== '' && !ctype_space($line)
                && !$this->startsBlock($line)
            ) {
                // Joined once at the end: rebuilding the token per line would be quadratic.
                $itemContinuations[array_key_last($tokens)][] = ltrim($line, " \t");
                continue;
            }

            // Start new indented code block (only when no paragraph was active). A line
            // indented 4+ columns is a list item only inside an open list.
            if (!$hadPendingLines
                && preg_match('/^    (.*)$/s', $expanded, $m)
                && (!$listOpen
                    || (!preg_match(self::PATTERN_UNORDERED_LIST, $line) && !preg_match(self::PATTERN_ORDERED_LIST, $line)))
            ) {
                $inIndentedBlock = true;
                $indentedLines   = [$this->stripLeadingColumns($line, 4)];
                $pendingBlanks   = [];
                continue;
            }

            $token = $this->matchLine($line);

            if ($token->type === TokenType::LIST_ITEM) {
                $token = $this->placeListItem($token, $line, $listOpen, $hadPendingLines, $listColumns);
            }

            // A LINK_DEFINITION with no title may have its title on the next line (CommonMark §4.7).
            if ($token->type === TokenType::LINK_DEFINITION && $token->meta['title'] === null) {
                $pendingLinkDef = $token;
                continue;
            }

            // A FOOTNOTE_DEFINITION token opens multi-line body accumulation.
            // Flush any pending setext heading candidate and pending link def first.
            if ($token->type === TokenType::FOOTNOTE_DEFINITION) {
                foreach ($pendingLines as $pt) {
                    $tokens[] = $pt;
                }
                $pendingLines = [];
                /** @psalm-suppress TypeDoesNotContainType @phpstan-ignore notIdentical.alwaysFalse */
                if ($pendingLinkDef !== null) {
                    $tokens[] = $pendingLinkDef;
                    $pendingLinkDef = null;
                }
                $inFootnoteBody    = true;
                $footnoteBodyLabel = $token->meta['label'];
                $footnoteBodyLines = [$token->meta['body']];
                continue;
            }

            // A plain paragraph line is held as pending to allow setext promotion on the next line.
            if ($token->type === TokenType::PARAGRAPH && ($line !== '' && !ctype_space($line))) {
                $pendingLines[] = $token;
                continue;
            }

            foreach ($pendingLines as $pt) {
                $tokens[] = $pt;
            }
            $pendingLines = [];
            $tokens[] = $token;
        }

        foreach ($itemContinuations as $idx => $continuation) {
            $item = $tokens[$idx];
            $tokens[$idx] = new Token(TokenType::LIST_ITEM, $item->content . "\n" . implode("\n", $continuation), $item->meta);
        }

        // Flush any remaining pending link def (no continuation title followed)
        if ($pendingLinkDef !== null) {
            $tokens[] = $pendingLinkDef;
        }

        // Flush any remaining pending lines
        foreach ($pendingLines as $pt) {
            $tokens[] = $pt;
        }

        // Flush any in-progress footnote definition body
        if ($inFootnoteBody && $footnoteBodyLines !== []) {
            $tokens[] = new Token(
                TokenType::FOOTNOTE_DEFINITION,
                '',
                ['label' => $footnoteBodyLabel, 'body' => implode(' ', $footnoteBodyLines)],
            );
        }

        // Unclosed HTML block at end of input — emit what was collected.
        if ($inHtmlBlock && $htmlLines !== []) {
            $tokens[] = new Token(TokenType::HTML_BLOCK, implode("\n", $htmlLines));
        }

        // Unclosed fenced block — emit what was collected
        if ($inFencedBlock) {
            $tokens[] = new Token(
                TokenType::FENCED_CODE,
                implode("\n", $fencedLines),
                ['language' => $fencedLanguage],
            );
        }

        // Unclosed indented code block — emit what was collected
        if ($inIndentedBlock && $indentedLines !== []) {
            $tokens[] = new Token(
                TokenType::INDENTED_CODE,
                implode("\n", $indentedLines) . "\n",
            );
        }

        // Unclosed columns block — emit what was collected
        if ($inColumnsBlock) {
            $tokens[] = new Token(
                TokenType::COLUMNS_CONTAINER,
                '',
                [
                    'left_raw'  => implode("\n", $columnsLeftLines),
                    'right_raw' => implode("\n", $columnsRightLines),
                ],
            );
        }

        return $tokens;
    }

    /**
     * Column where an item's content starts: after the marker and 1–4 spaces
     * (5+ spaces count as one: the rest is indented code, §5.2 rule 2).
     */
    private function listContentColumn(int $markerEnd, string $spacing): int
    {
        $width = strlen($this->expandTabs($spacing, $markerEnd));
        return $markerEnd + ($width >= 5 ? 1 : $width);
    }

    /**
     * Decide whether a matched list line really is a list item and at which depth.
     *
     * - Outside a list, a line indented 4+ columns is not a list item (it is code or
     *   paragraph text), and an ordered item can only interrupt a paragraph when it
     *   starts at 1 (§5.2).
     * - Depth is the number of open items whose content column the marker reaches.
     *
     * @param list<int> $listColumns open items' content columns (updated in place)
     */
    private function placeListItem(Token $token, string $line, bool $listOpen, bool $inParagraph, array &$listColumns): Token
    {
        if (!$listOpen) {
            $listColumns = [];
            if ($token->meta['indent'] >= 4 || ($inParagraph && $token->meta['ordered'] && $token->meta['start'] !== 1)) {
                return new Token(TokenType::PARAGRAPH, $line);
            }
        }

        while ($listColumns !== [] && end($listColumns) > $token->meta['indent']) {
            array_pop($listColumns);
        }
        $meta          = $token->meta;
        $meta['depth'] = count($listColumns);
        $listColumns[] = (int) $meta['contentCol'];

        return new Token(TokenType::LIST_ITEM, $token->content, $meta);
    }

    /**
     * Whether the last non-blank token is a list item (blank lines may separate items
     * of the same list). Only tokens added since the previous call are examined, so a
     * long run of blank lines stays linear.
     *
     * @param Token[]          $tokens
     * @param array{int, bool} $cache  [tokens examined so far, result]
     */
    private function lastNonBlankIsListItem(array $tokens, array &$cache): bool
    {
        [$seen, $result] = $cache;
        $count = count($tokens);
        for ($i = $count - 1; $i >= $seen; $i--) {
            if ($tokens[$i]->type !== TokenType::BLANK) {
                $result = $tokens[$i]->type === TokenType::LIST_ITEM;
                break;
            }
        }
        $cache = [$count, $result];
        return $result;
    }

    /** Whether $line starts a block that ends a paragraph (so it cannot continue one). */
    private function startsBlock(string $line): bool
    {
        return preg_match(self::PATTERN_FENCED_OPEN, $line) === 1
            || preg_match($this->patternHtmlBlockStart, $line) === 1
            || preg_match(self::PATTERN_HORIZONTAL_RULE, $line) === 1
            || preg_match(self::PATTERN_HEADING, $line) === 1
            || preg_match(self::PATTERN_BLOCKQUOTE, $line) === 1
            || preg_match(self::PATTERN_UNORDERED_LIST, $line) === 1
            || preg_match(self::PATTERN_ORDERED_LIST, $line) === 1
            || preg_match(self::PATTERN_COLUMNS_OPEN, $line) === 1
            || preg_match(self::PATTERN_FOOTNOTE_DEF, $line) === 1;
    }

    /** @return array{string, ?bool} */
    private function extractTaskChecked(string $content): array
    {
        if (preg_match('/^\[([ xX])\]\s+(.*)$/', $content, $m)) {
            return [$m[2], $m[1] !== ' '];
        }
        return [$content, null];
    }

    /**
     * Expand tab characters to spaces using 4-column tab stops (CommonMark §2.1).
     *
     * @param int $startCol Column position of the first character of $line (default 0).
     */
    private function expandTabs(string $line, int $startCol = 0): string
    {
        $out = '';
        $col = $startCol;
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];
            if ($ch === "\t") {
                $spaces = 4 - ($col % 4);
                $out .= str_repeat(' ', $spaces);
                $col += $spaces;
            } else {
                $out .= $ch;
                $col++;
            }
        }
        return $out;
    }

    /**
     * Return the suffix of $line after consuming exactly $cols columns,
     * prepending any overshoot spaces when a tab spans the boundary.
     */
    private function stripLeadingColumns(string $line, int $cols): string
    {
        $col = 0;
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            if ($col >= $cols) {
                return substr($line, $i);
            }
            $ch = $line[$i];
            if ($ch === "\t") {
                $tabStop = 4 - ($col % 4);
                $newCol  = $col + $tabStop;
                if ($newCol > $cols) {
                    $surplus = $newCol - $cols;
                    return str_repeat(' ', $surplus) . substr($line, $i + 1);
                }
                $col = $newCol;
            } else {
                $col++;
            }
        }
        return '';
    }

    /**
     * Strip blockquote markers from an already-expanded line.
     *
     * Implements CommonMark §5.1: each `>` consumes one mandatory character plus one
     * optional space. Operates on the expanded form so tab overshoot is already resolved.
     */
    private function stripBlockquoteMarkers(string $expandedLine, int $level): string
    {
        $pos = 0;
        $len = strlen($expandedLine);
        for ($l = 0; $l < $level; $l++) {
            if ($pos < $len && $expandedLine[$pos] === '>') {
                $pos++;
            }
            // Consume at most one optional space following the marker.
            if ($pos < $len && $expandedLine[$pos] === ' ') {
                $pos++;
            }
        }
        return substr($expandedLine, $pos);
    }

    private function matchLine(string $line): Token
    {
        if ($line === '' || ctype_space($line)) {
            return new Token(TokenType::BLANK, '');
        }

        if (preg_match(self::PATTERN_HORIZONTAL_RULE, $line)) {
            return new Token(TokenType::HORIZONTAL_RULE, '');
        }

        if (preg_match(self::PATTERN_HEADING, $line, $m)) {
            $content = (string) preg_replace('/\s+#+\s*$|^#+$/', '', trim($m[2]));
            return new Token(
                TokenType::HEADING,
                $content,
                ['level' => strlen($m[1])],
            );
        }

        if (preg_match(self::PATTERN_BLOCKQUOTE, $line, $m)) {
            // Compute inner content from the expanded line using the CommonMark §5.1 rule:
            // each '>' marker consumes the '>' character and optionally one space.
            // Operating on the expanded form ensures tabs in the prefix are correctly handled.
            $level = substr_count($m[1], '>');
            $expandedLine = $this->expandTabs($line);
            $innerContent = $this->stripBlockquoteMarkers($expandedLine, $level);
            return new Token(
                TokenType::BLOCKQUOTE,
                $innerContent,
                ['level' => $level],
            );
        }

        // 'depth' is a placeholder: tokenize() computes it from the open items' columns.
        if (preg_match(self::PATTERN_UNORDERED_LIST, $line, $m)) {
            [$content, $checked] = $this->extractTaskChecked(trim($m[4]));
            return new Token(
                TokenType::LIST_ITEM,
                $content,
                [
                    'ordered'   => false,
                    'depth'     => 0,
                    'checked'   => $checked,
                    'marker'    => $m[2],
                    'start'     => null,
                    'indent'    => strlen($m[1]),
                    'contentCol' => $this->listContentColumn(strlen($m[1]) + 1, $m[3]),
                ],
            );
        }

        if (preg_match(self::PATTERN_ORDERED_LIST, $line, $m)) {
            [$content, $checked] = $this->extractTaskChecked(trim($m[5]));
            return new Token(
                TokenType::LIST_ITEM,
                $content,
                [
                    'ordered'   => true,
                    'depth'     => 0,
                    'checked'   => $checked,
                    'marker'    => $m[3],
                    'start'     => (int) $m[2],
                    'indent'    => strlen($m[1]),
                    'contentCol' => $this->listContentColumn(strlen($m[1]) + strlen($m[2]) + 1, $m[4]),
                ],
            );
        }

        if (str_contains($line, '|')) {
            if (preg_match(self::PATTERN_TABLE_SEPARATOR, $line)) {
                return new Token(TokenType::TABLE_SEPARATOR, $line);
            }
            if (preg_match(self::PATTERN_TABLE_ROW, $line)) {
                return new Token(TokenType::TABLE_ROW, $line);
            }
        }

        // Footnote definition: [^label]: body — must run before LINK_DEFINITION
        // because [^label]: body also matches PATTERN_LINK_DEFINITION (label=[^label], href=body).
        if (preg_match(self::PATTERN_FOOTNOTE_DEF, $line, $m)) {
            return new Token(
                TokenType::FOOTNOTE_DEFINITION,
                $line,
                ['label' => $m[1], 'body' => $m[2]],
            );
        }

        // URL validation is intentionally deferred to InlineParser::isSafeUrl() at resolution time.
        if (preg_match(self::PATTERN_LINK_DEFINITION, $line, $m)) {
            // Groups: 2=angle-bracket URL content (stripped), 3=bare URL.
            // When the angle-bracket branch <(...)> matches, $m[3] is absent or empty ''.
            // When the bare URL branch (\S+) matches, $m[3] is non-empty (bare URLs cannot be empty).
            // They are mutually exclusive (alternation); use non-empty $m[3] to detect bare URL.
            $isAngleBracket = ($m[3] ?? '') === '';
            if ($isAngleBracket) {
                // Angle-bracket branch: unescape backslash sequences (e.g. \> → >)
                $href = (string) preg_replace('/\\\\(.)/', '$1', $m[2]);
            } else {
                // Bare URL branch. If it starts with '<', it was an attempted but invalid
                // angle-bracket URL (e.g. unclosed or containing unescaped '<') — reject it.
                if (str_starts_with($m[3], '<')) {
                    return new Token(TokenType::PARAGRAPH, $line);
                }
                $href = $m[3];
            }
            if (strlen($href) > 2048) {
                return new Token(TokenType::PARAGRAPH, $line);
            }
            // Groups: 4=double-quote title, 5=single-quote title, 6=paren title
            $rawTitle = ($m[4] ?? '') !== '' ? $m[4] : (($m[5] ?? '') !== '' ? $m[5] : (($m[6] ?? '') !== '' ? $m[6] : null));
            // Strip control characters from title (U+0000–U+001F, U+007F)
            $title = $rawTitle !== null ? preg_replace('/[\x00-\x1F\x7F]/', '', $rawTitle) : null;
            return new Token(
                TokenType::LINK_DEFINITION,
                $line,
                [
                    'label' => $m[1],
                    'href'  => $href,
                    'title' => ($title !== null && $title !== '') ? $title : null,
                ],
            );
        }

        // Two+ trailing ASCII spaces → hard break (CommonMark §6.7). Intentionally
        // matches only 0x20 pairs; tab-space mixes are not treated as hard breaks.
        if (substr_compare($line, '  ', -2) === 0) {
            return new Token(
                TokenType::PARAGRAPH,
                rtrim($line),
                ['hard_break' => true],
            );
        }

        // Odd number of trailing backslashes = unescaped final backslash → hard break (CommonMark §6.7).
        if (preg_match('/\\\\+$/', $line, $m) && strlen($m[0]) % 2 === 1) {
            return new Token(
                TokenType::PARAGRAPH,
                substr($line, 0, -1),
                ['hard_break' => true],
            );
        }

        return new Token(TokenType::PARAGRAPH, $line);
    }
}
