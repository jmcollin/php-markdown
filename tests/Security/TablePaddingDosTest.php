<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Security;

use PhpMarkdown\MarkdownParser;
use PHPUnit\Framework\TestCase;

/**
 * Completing short table rows creates cells that cost no input, so a wide header over
 * many empty rows could turn 1 MiB of Markdown into billions of cells and exhaust memory.
 */
final class TablePaddingDosTest extends TestCase
{
    /** Same value as Parser::MAX_TABLE_PADDING_CELLS: the limit is part of the contract. */
    private const LIMIT = 100_000;

    private function table(int $columns, int $rows, string $row = "|x|\n"): string
    {
        return str_repeat('|a', $columns) . "|\n" . str_repeat('|-', $columns) . "|\n" . str_repeat($row, $rows);
    }

    public function testWideHeaderOverManyShortRowsStaysBounded(): void
    {
        $start = microtime(true);
        $html  = (new MarkdownParser())->parse($this->table(3_000, 60_000));
        $this->assertLessThan(10.0, microtime(true) - $start);

        // Would be 180 million cells if every row were completed.
        $this->assertLessThanOrEqual(self::LIMIT + 3_000 + 60_000, substr_count($html, '<td>') + substr_count($html, '<th>'));
        // Past the limit, what the author wrote is still rendered.
        $this->assertSame(60_000, substr_count($html, '<td>x</td>'));
    }

    public function testLimitIsSharedByAllTablesOfADocument(): void
    {
        // Each table stays under the limit (99,000 cells added): a per-table limit would
        // allow them all, for 297,000 cells instead of 100,000.
        $html = (new MarkdownParser())->parse(str_repeat($this->table(1_001, 99) . "\ntext\n\n", 3));

        $this->assertSame(self::LIMIT + 3 * 99, substr_count($html, '<td>'));
    }

    public function testRowsWithinTheLimitAreCompleted(): void
    {
        // Exactly at the limit: 100 rows missing 1 000 cells each.
        $html = (new MarkdownParser())->parse($this->table(1_001, 100));

        $this->assertSame(100 * 1_001, substr_count($html, '<td>'));
    }

    public function testOneCellPastTheLimitIsNotAdded(): void
    {
        $html = (new MarkdownParser())->parse($this->table(1_001, 100) . "\n" . $this->table(2, 1));

        $this->assertSame(100 * 1_001 + 1, substr_count($html, '<td>'));
    }

    public function testLimitRestartsForEachDocument(): void
    {
        // One MarkdownParser instance is reused: the second document must not inherit
        // what the first one consumed.
        $parser = new MarkdownParser();
        $md     = $this->table(1_001, 100);

        $this->assertSame($parser->parse($md), $parser->parse($md));
    }
}
