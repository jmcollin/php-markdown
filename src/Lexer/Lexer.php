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
    private const PATTERN_HEADING        = '/^(#{1,6})\s+(.+)$/';
    private const PATTERN_FENCED_OPEN    = '/^(`{3,})\s*([A-Za-z0-9_-]*)\s*$/';
    private const PATTERN_FENCED_CLOSE   = '/^`{3,}$/';
    private const PATTERN_BLOCKQUOTE     = '/^(>+)\s*(.*)/';
    private const PATTERN_UNORDERED_LIST = '/^( *)[-*+]\s+(.+)/';
    private const PATTERN_ORDERED_LIST   = '/^( *)\d+\.\s+(.+)/';
    private const PATTERN_HORIZONTAL_RULE = '/^(-{3,}|\*{3,}|_{3,})\s*$/';
    private const PATTERN_TABLE_ROW       = '/^\|?.+\|.+\|?$/';
    private const PATTERN_TABLE_SEPARATOR = '/^\|?[ \t:|-]+(?:\|[ \t:|-]+)+\|?$/';

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

        foreach ($lines as $raw) {
            $line = rtrim($raw, "\r");

            if ($inFencedBlock) {
                if (preg_match(self::PATTERN_FENCED_CLOSE, $line)) {
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
                $inFencedBlock = true;
                $fencedLanguage = $m[2];
                $fencedLines = [];
                continue;
            }

            $tokens[] = $this->matchLine($line);
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
                ['level' => strlen($m[1])], // reserved for future nested-blockquote rendering
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

        return new Token(TokenType::PARAGRAPH, $line);
    }
}
