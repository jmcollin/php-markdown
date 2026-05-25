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
    private const PATTERN_HEADING           = '/^(#{1,6})\s+(.+)$/';
    private const PATTERN_FENCED_OPEN      = '/^ {0,3}([`~]{3,})\s*(\S*)\s*$/';
    private const PATTERN_BLOCKQUOTE       = '/^((?:>[ \t]*)++)(.*)/';
    private const PATTERN_UNORDERED_LIST   = '/^( *)[-*+]\s+(.+)/';
    private const PATTERN_ORDERED_LIST     = '/^( *)\d+\.\s+(.+)/';
    private const PATTERN_HORIZONTAL_RULE  = '/^(-{3,}|\*{3,}|_{3,})\s*$/';
    private const PATTERN_LINK_DEFINITION  = '/^\[([^\]\[]+)\]:\s+(\S+)(?:\s+"([^"]*)")?$/';
    private const PATTERN_TABLE_ROW        = '/^\|?[^|]+(?:\|[^|]+)+\|?$/';
    private const PATTERN_TABLE_SEPARATOR  = '/^\|?[ \t:|-]+(?:\|[ \t:|-]+)+\|?$/';
    private const PATTERN_SETEXT_H1        = '/^=+\s*$/';
    private const PATTERN_SETEXT_H2        = '/^-+\s*$/';
    private const PATTERN_COLUMNS_OPEN     = '/^:::\s*columns\s*$/i';
    private const PATTERN_COLUMNS_CLOSE    = '/^:::$/';
    private const PATTERN_COLUMNS_SEP      = '/^\|\|\|$/';
    private const PATTERN_INDENTED_CODE    = '/^(    |\t)(.*)/s';

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
        $pendingToken = null;

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

        foreach ($lines as $raw) {
            $line = rtrim($raw, "\r");

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
                    if ($pendingToken !== null) {
                        $tokens[] = $pendingToken;
                        $pendingToken = null;
                    }
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
                if ($pendingToken !== null) {
                    $tokens[] = $pendingToken;
                    $pendingToken = null;
                }
                $inColumnsBlock    = true;
                $columnsSepFound   = false;
                $columnsLeftLines  = [];
                $columnsRightLines = [];
                continue;
            }

            if ($inFencedBlock) {
                if (preg_match('/^ {0,3}' . preg_quote($fenceChar, '/') . '{' . $fenceLength . ',}\s*$/', $line)) {
                    if ($pendingToken !== null) {
                        $tokens[] = $pendingToken;
                        $pendingToken = null;
                    }
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
                if ($pendingToken !== null) {
                    $tokens[] = $pendingToken;
                    $pendingToken = null;
                }
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
                if ($pendingToken !== null) {
                    $tokens[] = $pendingToken;
                    $pendingToken = null;
                }
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

            // Capture paragraph-active state before setext promotion may clear it.
            $hadPendingToken = $pendingToken !== null;

            // Setext heading detection: a pending text line followed by === or ---
            if ($pendingToken !== null) {
                if (preg_match(self::PATTERN_SETEXT_H1, $line)) {
                    $tokens[] = new Token(TokenType::HEADING, $pendingToken->content, ['level' => 1]);
                    $pendingToken = null;
                    continue;
                }
                if (preg_match(self::PATTERN_SETEXT_H2, $line)) {
                    $tokens[] = new Token(TokenType::HEADING, $pendingToken->content, ['level' => 2]);
                    $pendingToken = null;
                    continue;
                }
                $tokens[] = $pendingToken;
                $pendingToken = null;
            }

            // Indented code block drain (continuation).
            if ($inIndentedBlock) {
                if (preg_match(self::PATTERN_INDENTED_CODE, $line, $m)
                    && !preg_match(self::PATTERN_UNORDERED_LIST, $line)
                    && !preg_match(self::PATTERN_ORDERED_LIST, $line)
                ) {
                    $indentedLines = [...$indentedLines, ...$pendingBlanks, $m[2]];
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

            // Start new indented code block (only when no paragraph was active,
            // and only when the line is not a list item — list items with leading spaces
            // are handled by matchLine() via PATTERN_UNORDERED_LIST / PATTERN_ORDERED_LIST).
            if (!$hadPendingToken
                && preg_match(self::PATTERN_INDENTED_CODE, $line, $m)
                && !preg_match(self::PATTERN_UNORDERED_LIST, $line)
                && !preg_match(self::PATTERN_ORDERED_LIST, $line)
            ) {
                $inIndentedBlock = true;
                $indentedLines   = [$m[2]];
                $pendingBlanks   = [];
                continue;
            }

            $token = $this->matchLine($line);

            // A plain paragraph line is held as pending to allow setext promotion on the next line.
            if ($token->type === TokenType::PARAGRAPH && ($line !== '' && !ctype_space($line))) {
                $pendingToken = $token;
                continue;
            }

            $tokens[] = $token;
        }

        // Flush any remaining pending token
        if ($pendingToken !== null) {
            $tokens[] = $pendingToken;
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

    /** @return array{string, ?bool} */
    private function extractTaskChecked(string $content): array
    {
        if (preg_match('/^\[([ xX])\]\s+(.*)$/', $content, $m)) {
            return [$m[2], $m[1] !== ' '];
        }
        return [$content, null];
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
            return new Token(
                TokenType::BLOCKQUOTE,
                trim($m[2]),
                ['level' => substr_count($m[1], '>')],
            );
        }

        if (preg_match(self::PATTERN_UNORDERED_LIST, $line, $m)) {
            [$content, $checked] = $this->extractTaskChecked(trim($m[2]));
            return new Token(
                TokenType::LIST_ITEM,
                $content,
                ['ordered' => false, 'depth' => (int) floor(strlen($m[1]) / 2), 'checked' => $checked],
            );
        }

        if (preg_match(self::PATTERN_ORDERED_LIST, $line, $m)) {
            [$content, $checked] = $this->extractTaskChecked(trim($m[2]));
            return new Token(
                TokenType::LIST_ITEM,
                $content,
                ['ordered' => true, 'depth' => (int) floor(strlen($m[1]) / 2), 'checked' => $checked],
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

        // URL validation is intentionally deferred to InlineParser::isSafeUrl() at resolution time.
        if (preg_match(self::PATTERN_LINK_DEFINITION, $line, $m)) {
            $href = $m[2];
            if (strlen($href) > 2048) {
                return new Token(TokenType::PARAGRAPH, $line);
            }
            $rawTitle = isset($m[3]) && $m[3] !== '' ? $m[3] : null;
            // Strip control characters from title (U+0000–U+001F, U+007F)
            $title = $rawTitle !== null ? preg_replace('/[\x00-\x1F\x7F]/', '', $rawTitle) : null;
            return new Token(
                TokenType::LINK_DEFINITION,
                $line,
                [
                    'label' => $m[1],
                    'href'  => $href,
                    'title' => $title !== '' ? $title : null,
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
