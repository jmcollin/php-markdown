<?php

declare(strict_types=1);

namespace PhpMarkdown\Parser;

/**
 * Computes the left-flanking and right-flanking properties of a delimiter run
 * according to CommonMark spec §6.2.
 */
final class FlankingComputer
{
    /**
     * @return array{canOpen: bool, canClose: bool}
     */
    public static function compute(string $text, int $pos, int $runLen, string $char): array
    {
        // Read the neighbouring UTF-8 characters directly from the byte offsets.
        // Computing character offsets (mb_strlen of the prefix) here would cost O(n)
        // per delimiter run and make emphasis parsing quadratic.
        $prev = self::charBefore($text, $pos);
        $next = self::charAt($text, $pos + $runLen);

        // CommonMark §6.2 left- and right-flanking definitions.
        $leftFlanking  = !self::isUnicodeWhitespace($next)
            && (!self::isUnicodePunctuation($next) || self::isUnicodeWhitespace($prev) || self::isUnicodePunctuation($prev));

        $rightFlanking = !self::isUnicodeWhitespace($prev)
            && (!self::isUnicodePunctuation($prev) || self::isUnicodeWhitespace($next) || self::isUnicodePunctuation($next));

        if ($char === '*') {
            $canOpen  = $leftFlanking;
            $canClose = $rightFlanking;
        } else {
            // '_' has additional intraword restrictions.
            $canOpen  = $leftFlanking  && (!$rightFlanking || self::isUnicodePunctuation($prev));
            $canClose = $rightFlanking && (!$leftFlanking  || self::isUnicodePunctuation($next));
        }

        return ['canOpen' => $canOpen, 'canClose' => $canClose];
    }

    /** The UTF-8 character ending just before byte offset $pos ('' at start of text). */
    private static function charBefore(string $text, int $pos): string
    {
        if ($pos <= 0) {
            return '';
        }
        $start = $pos - 1;
        // Step back over continuation bytes (10xxxxxx), at most 3 of them.
        while ($start > 0 && $pos - $start < 4 && (ord($text[$start]) & 0xC0) === 0x80) {
            $start--;
        }
        return substr($text, $start, $pos - $start);
    }

    /** The UTF-8 character starting at byte offset $pos ('' at end of text). */
    private static function charAt(string $text, int $pos): string
    {
        if ($pos >= strlen($text)) {
            return '';
        }
        $lead = ord($text[$pos]);
        $len  = match (true) {
            $lead >= 0xF0 => 4,
            $lead >= 0xE0 => 3,
            $lead >= 0xC0 => 2,
            default       => 1,
        };
        return substr($text, $pos, $len);
    }

    /**
     * '' sentinel (SOL/EOL) is treated as Unicode whitespace.
     */
    private static function isUnicodeWhitespace(string $ch): bool
    {
        if ($ch === '') {
            return true;
        }
        if (in_array($ch, [' ', "\t", "\n", "\r"], true)) {
            return true;
        }
        return (bool) preg_match('/^[\p{Z}\p{C}]/u', $ch);
    }

    private static function isUnicodePunctuation(string $ch): bool
    {
        if ($ch === '') {
            return false;
        }
        return (bool) preg_match('/^[\p{P}\p{S}]/u', $ch);
    }
}
