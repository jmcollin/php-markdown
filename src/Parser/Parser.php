<?php

declare(strict_types=1);

namespace PhpMarkdown\Parser;

use PhpMarkdown\Exception\ParseException;
use PhpMarkdown\Lexer\Token;
use PhpMarkdown\Lexer\TokenType;
use PhpMarkdown\Node\Block\BlockquoteNode;
use PhpMarkdown\Node\Block\DocumentNode;
use PhpMarkdown\Node\Block\FencedCodeNode;
use PhpMarkdown\Node\Block\HeadingNode;
use PhpMarkdown\Node\Block\HorizontalRuleNode;
use PhpMarkdown\Node\Block\ListItemNode;
use PhpMarkdown\Node\Block\ListNode;
use PhpMarkdown\Node\Block\ParagraphNode;
use PhpMarkdown\Node\Block\TableCellNode;
use PhpMarkdown\Node\Block\TableNode;
use PhpMarkdown\Node\Block\TableRowNode;

/**
 * Consumes a Token sequence and builds a block-level AST.
 *
 * Delegates inline content parsing to InlineParser.
 */
final class Parser
{
    private readonly InlineParser $inlineParser;

    public function __construct()
    {
        $this->inlineParser = new InlineParser();
    }

    /**
     * @param Token[] $tokens
     * @throws ParseException
     */
    public function parse(array $tokens): DocumentNode
    {
        $children = [];
        $i = 0;
        $count = count($tokens);

        while ($i < $count) {
            $token = $tokens[$i];

            if ($token->type === TokenType::BLANK) {
                $i++;
                continue;
            }

            if ($token->type === TokenType::HEADING) {
                $children[] = new HeadingNode(
                    level: $token->meta['level'],
                    children: $this->inlineParser->parse($token->content),
                );
                $i++;
                continue;
            }

            if ($token->type === TokenType::FENCED_CODE) {
                $children[] = new FencedCodeNode(
                    content: $token->content,
                    language: $token->meta['language'] ?? '',
                );
                $i++;
                continue;
            }

            if ($token->type === TokenType::HORIZONTAL_RULE) {
                $children[] = new HorizontalRuleNode();
                $i++;
                continue;
            }

            if ($token->type === TokenType::BLOCKQUOTE) {
                // Collect consecutive blockquote tokens into one node
                $bqTokens = [];
                while ($i < $count && $tokens[$i]->type === TokenType::BLOCKQUOTE) {
                    $bqTokens[] = $tokens[$i];
                    $i++;
                }
                $children[] = $this->buildBlockquote($bqTokens);
                continue;
            }

            if ($token->type === TokenType::LIST_ITEM) {
                $ordered = $token->meta['ordered'];
                $items = [];
                while ($i < $count && $tokens[$i]->type === TokenType::LIST_ITEM && $tokens[$i]->meta['ordered'] === $ordered) {
                    $items[] = new ListItemNode(
                        children: $this->inlineParser->parse($tokens[$i]->content),
                    );
                    $i++;
                }
                $children[] = new ListNode(ordered: $ordered, children: $items);
                continue;
            }

            if ($token->type === TokenType::TABLE_ROW) {
                $children[] = $this->buildTable($tokens, $i);
                continue;
            }

            if ($token->type === TokenType::PARAGRAPH) {
                // Merge consecutive paragraph tokens (soft-wrapped lines)
                $lines = [];
                while ($i < $count && $tokens[$i]->type === TokenType::PARAGRAPH) {
                    $lines[] = $tokens[$i]->content;
                    $i++;
                }
                // CommonMark spec: paragraph continuation lines join with a single space.
                $children[] = new ParagraphNode(
                    children: $this->inlineParser->parse(implode(' ', $lines)),
                );
                continue;
            }

            $i++;
        }

        return new DocumentNode($children);
    }

    /**
     * @param Token[] $tokens
     */
    private function buildTable(array $tokens, int &$i): TableNode
    {
        $count = count($tokens);
        $rows = [];

        // Header row
        $headerCells = $this->parseCells($tokens[$i]->content);
        $i++;

        // Separator row — extract alignment, advance
        $aligns = [];
        if ($i < $count && $tokens[$i]->type === TokenType::TABLE_SEPARATOR) {
            $aligns = $this->parseAlignments($tokens[$i]->content);
            $i++;
        }

        $rows[] = new TableRowNode(
            cells: array_map(
                fn(string $cell, int $idx) => new TableCellNode(
                    children: $this->inlineParser->parse($cell),
                    align: $aligns[$idx] ?? '',
                ),
                $headerCells,
                array_keys($headerCells),
            ),
            isHeader: true,
        );

        // Body rows
        while ($i < $count && $tokens[$i]->type === TokenType::TABLE_ROW) {
            $cells = $this->parseCells($tokens[$i]->content);
            $rows[] = new TableRowNode(
                cells: array_map(
                    fn(string $cell, int $idx) => new TableCellNode(
                        children: $this->inlineParser->parse($cell),
                        align: $aligns[$idx] ?? '',
                    ),
                    $cells,
                    array_keys($cells),
                ),
                isHeader: false,
            );
            $i++;
        }

        return new TableNode(rows: $rows);
    }

    /** @return string[] */
    private function parseCells(string $line): array
    {
        $line = trim($line, ' |');
        return array_map(trim(...), explode('|', $line));
    }

    /** @return string[] */
    private function parseAlignments(string $separator): array
    {
        $separator = trim($separator, ' |');
        $aligns = [];
        foreach (explode('|', $separator) as $col) {
            $col = trim($col);
            $left  = str_starts_with($col, ':');
            $right = str_ends_with($col, ':');
            $aligns[] = match (true) {
                $left && $right => 'center',
                $right          => 'right',
                $left           => 'left',
                default         => '',
            };
        }
        return $aligns;
    }

    /**
     * @param Token[] $tokens
     */
    private function buildBlockquote(array $tokens): BlockquoteNode
    {
        $paragraphs = [];
        $lines = [];
        foreach ($tokens as $token) {
            if ($token->content !== '') {
                $lines[] = $token->content;
            }
        }
        if ($lines !== []) {
            $paragraphs[] = new ParagraphNode(
                children: $this->inlineParser->parse(implode(' ', $lines)),
            );
        }
        return new BlockquoteNode(children: $paragraphs);
    }
}
