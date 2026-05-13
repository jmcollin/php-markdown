<?php

declare(strict_types=1);

namespace PhpMarkdown\Parser;

/**
 * Extracts a YAML-subset front matter block from the top of a Markdown string.
 *
 * Recognised syntax:
 *   ---
 *   key: value
 *   ---
 *
 * Supported value types: string, int, float, bool (true/false), null.
 * Note: `yes`/`no` are treated as strings (not bool) to avoid country-code collisions.
 * Note: floats lose trailing zeros (e.g. `1.10` → `1.1`) — this is PHP float behaviour.
 * Sequences and mappings are not supported — values are always treated as strings
 * unless they parse as a scalar primitive.
 */
final class FrontMatterParser
{
    private const DELIMITER = '---';

    /**
     * @return array{markdown: string, meta: array<string, mixed>}
     */
    public function extract(string $input): array
    {
        $input = ltrim($input, "\r\n");

        if (!str_starts_with($input, self::DELIMITER)) {
            return ['markdown' => $input, 'meta' => []];
        }

        $after = substr($input, strlen(self::DELIMITER));

        // Must be followed by newline (or optional spaces then newline)
        if (!preg_match('/^[ \t]*\r?\n/', $after)) {
            return ['markdown' => $input, 'meta' => []];
        }

        $closePos = $this->findClose($after);

        if ($closePos === null) {
            return ['markdown' => $input, 'meta' => []];
        }

        [$yamlBlock, $rest] = $closePos;

        return [
            'markdown' => ltrim($rest, "\r\n"),
            'meta'     => $this->parseYaml($yamlBlock),
        ];
    }

    /**
     * Find the closing `---` delimiter.
     *
     * @return array{string, string}|null  [yaml_body, rest_of_document]
     */
    private function findClose(string $afterOpen): array|null
    {
        $lines = explode("\n", $afterOpen);
        $yamlLines = [];

        foreach ($lines as $idx => $line) {
            $trimmed = rtrim($line, "\r");
            if ($trimmed === self::DELIMITER) {
                $rest = implode("\n", array_slice($lines, $idx + 1));
                return [implode("\n", $yamlLines), $rest];
            }
            $yamlLines[] = $trimmed;
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function parseYaml(string $block): array
    {
        $meta = [];

        foreach (explode("\n", $block) as $line) {
            $line = rtrim($line, "\r");

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $colonPos = strpos($line, ':');
            if ($colonPos === false) {
                continue;
            }

            $key   = trim(substr($line, 0, $colonPos));
            $value = trim(substr($line, $colonPos + 1));

            // Reject empty or non-identifier keys; skip duplicate keys silently.
            if ($key === '' || !preg_match('/^[A-Za-z][A-Za-z0-9_\-]*$/', $key)) {
                continue;
            }
            if (array_key_exists($key, $meta)) {
                continue;
            }

            $meta[$key] = $this->castValue($value);
        }

        return $meta;
    }

    private function castValue(string $value): mixed
    {
        // Quoted string — strip quotes (require at least 2 chars to avoid single-quote edge case).
        if (
            strlen($value) >= 2 &&
            (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            )
        ) {
            return substr($value, 1, -1);
        }

        if ($value === '' || strtolower($value) === 'null' || $value === '~') {
            return null;
        }

        if (strtolower($value) === 'true') {
            return true;
        }

        if (strtolower($value) === 'false') {
            return false;
        }

        // Require no leading zeros to avoid 0123 → 123 type confusion.
        if (preg_match('/^-?(?:0|[1-9]\d*)$/', $value)) {
            return (int) $value;
        }

        if (preg_match('/^-?\d+\.\d+$/', $value)) {
            return (float) $value;
        }

        return $value;
    }
}
