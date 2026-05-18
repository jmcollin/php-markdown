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
    private const PATTERN_FENCED_OPEN      = '/^(`{3,})\s*([A-Za-z0-9_-]*)\s*$/';
    private const PATTERN_FENCED_CLOSE     = '/^`{3,}$/';
    private const PATTERN_BLOCKQUOTE       = '/^((?:>[ \t]*)++)(.*)/';
    private const PATTERN_UNORDERED_LIST   = '/^( *)[-*+]\s+(.+)/';
    private const PATTERN_ORDERED_LIST     = '/^( *)\d+\.\s+(.+)/';
    private const PATTERN_HORIZONTAL_RULE  = '/^(-{3,}|\*{3,}|_{3,})\s*$/';
    private const PATTERN_LINK_DEFINITION  = '/^\[([^\]\[]+)\]:\s+(\S+)(?:\s+"([^"]*)")?$/';
    private const PATTERN_TABLE_ROW        = '/^\|?[^|]+\|[^|]+\|?$/';
    private const PATTERN_TABLE_SEPARATOR  = '/^\|?[ \t:|-]+(?:\|[ \t:|-]+)+\|?$/';
    private const PATTERN_SETEXT_H1        = '/^=+\s*$/';
    private const PATTERN_SETEXT_H2        = '/^-+\s*$/';

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
        $pendingToken = null;

        foreach ($lines as $raw) {
            $line = rtrim($raw, "\r");

            if ($inFencedBlock) {
                if (preg_match(self::PATTERN_FENCED_CLOSE, $line)) {
                    if ($pendingToken !== null) {
                        $tokens[] = $pendingToken;
                        $pendingToken = null;
                    }
                    $tokens[] = new Token(
                        TokenType::FENCED_CODE,
                        implode("\n", $fencedLines),
                        ['language' => $fencedLanguage],
                    );
                    $inFencedBlock = false;
                    $fencedLanguage = '';
                    $fencedLines = [];
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
                $inFencedBlock = true;
                $fencedLanguage = $m[2];
                $fencedLines = [];
                continue;
            }

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

        // Unclosed fenced block — emit what was collected
        if ($inFencedBlock) {
            $tokens[] = new Token(
                TokenType::FENCED_CODE,
                implode("\n", $fencedLines),
                ['language' => $fencedLanguage],
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
            return new Token(
                TokenType::HEADING,
                trim($m[2]),
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
