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
        // Extract the codepoint immediately before the run.
        if ($pos === 0) {
            $prev = '';
        } else {
            $mbPos = mb_strlen(substr($text, 0, $pos), 'UTF-8');
            $prev  = $mbPos > 0 ? mb_substr($text, $mbPos - 1, 1, 'UTF-8') : '';
        }

        // Extract the codepoint immediately after the run.
        $endPos = $pos + $runLen;
        if ($endPos >= strlen($text)) {
            $next = '';
        } else {
            $mbEnd = mb_strlen(substr($text, 0, $endPos), 'UTF-8');
            $next  = mb_substr($text, $mbEnd, 1, 'UTF-8');
        }

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
