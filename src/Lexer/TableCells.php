<?php

declare(strict_types=1);

namespace PhpMarkdown\Lexer;

/**
 * Splits a GFM table row into cells.
 *
 * Cells are separated by unescaped '|'; one leading and one trailing pipe are optional.
 * An escaped pipe (\|) never separates cells — it is kept as '\|' in the cell text so the
 * caller decides how to unescape it (GFM replaces it with '|' before inline parsing,
 * including inside code spans).
 */
final class TableCells
{
    /** @return list<string> trimmed raw cell contents */
    public static function split(string $line): array
    {
        $line = trim($line, " \t");
        if (str_starts_with($line, '|')) {
            $line = substr($line, 1);
        }
        if (str_ends_with($line, '|') && !self::isEscaped($line, strlen($line) - 1)) {
            $line = substr($line, 0, -1);
        }

        $cells = [];
        $cell  = '';
        $len   = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];
            if ($ch === '\\' && $i + 1 < $len) {
                $cell .= $ch . $line[$i + 1];
                $i++;
                continue;
            }
            if ($ch === '|') {
                $cells[] = trim($cell, " \t");
                $cell    = '';
                continue;
            }
            $cell .= $ch;
        }
        $cells[] = trim($cell, " \t");

        return $cells;
    }

    /** Whether $line contains at least one unescaped pipe. */
    public static function hasPipe(string $line): bool
    {
        return count(self::split('x' . $line . 'x')) > 1;
    }

    /**
     * Parse a delimiter row ("| :-- | --: |"). Returns one alignment per column
     * ('left', 'right', 'center' or ''), or null if $line is not a delimiter row.
     *
     * @return list<string>|null
     */
    public static function alignments(string $line): ?array
    {
        if (!self::hasPipe($line)) {
            return null;
        }
        $aligns = [];
        foreach (self::split($line) as $cell) {
            if (!preg_match('/^(:?)-+(:?)$/', $cell, $m)) {
                return null;
            }
            $aligns[] = match (true) {
                $m[1] !== '' && $m[2] !== '' => 'center',
                $m[2] !== ''                 => 'right',
                $m[1] !== ''                 => 'left',
                default                      => '',
            };
        }
        return $aligns;
    }

    private static function isEscaped(string $line, int $pos): bool
    {
        $backslashes = 0;
        for ($i = $pos - 1; $i >= 0 && $line[$i] === '\\'; $i--) {
            $backslashes++;
        }
        return $backslashes % 2 === 1;
    }
}
