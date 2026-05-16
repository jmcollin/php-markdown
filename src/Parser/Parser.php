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
use PhpMarkdown\Node\Inline\HardBreakNode;
use PhpMarkdown\Node\Inline\TextNode;
use PhpMarkdown\Node\InlineNodeInterface;

/**
 * Consumes a Token sequence and builds a block-level AST.
 *
 * Delegates inline content parsing to InlineParser.
 */
final class Parser
{
    private readonly InlineParser $inlineParser;

    /** @var array<string, array{href: string, title: ?string}> */
    private array $linkRefs = [];

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
        ['refs' => $this->linkRefs, 'tokens' => $tokens] = $this->extractLinkDefinitions($tokens);

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
                    children: $this->inlineParser->parse($token->content, $this->linkRefs),
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
                $children[] = $this->buildList($tokens, $i, $tokens[$i]->meta['depth']);
                continue;
            }

            if ($token->type === TokenType::TABLE_ROW) {
                $children[] = $this->buildTable($tokens, $i);
                continue;
            }

            if ($token->type === TokenType::PARAGRAPH) {
                $paraTokens = [];
                while ($i < $count && $tokens[$i]->type === TokenType::PARAGRAPH) {
                    $paraTokens[] = $tokens[$i];
                    $i++;
                }
                $children[] = new ParagraphNode(
                    children: $this->buildParagraphChildren($paraTokens),
                );
                continue;
            }

            $i++;
        }

        return new DocumentNode($children);
    }

    /**
     * @param Token[] $tokens
     * @return InlineNodeInterface[]
     */
    private function buildParagraphChildren(array $tokens): array
    {
        $result = [];
        $lastIdx = count($tokens) - 1;

        foreach ($tokens as $idx => $token) {
            $inlineNodes = $this->inlineParser->parse($token->content, $this->linkRefs);
            array_push($result, ...$inlineNodes);

            if ($idx < $lastIdx) {
                // Hard break on last token is stripped per CommonMark §6.7.
                $result[] = ($token->meta['hard_break'] ?? false)
                    ? new HardBreakNode()
                    : new TextNode(' ');
            }
        }

        return $result;
    }

    /**
     * Recursively build a ListNode from a flat sequence of LIST_ITEM tokens.
     *
     * @param Token[] $tokens
     */
    private function buildList(array $tokens, int &$i, int $depth): ListNode
    {
        $count   = count($tokens);
        $ordered = $tokens[$i]->meta['ordered'];
        $items   = [];

        while ($i < $count
            && $tokens[$i]->type === TokenType::LIST_ITEM
            && $tokens[$i]->meta['depth'] === $depth
            && $tokens[$i]->meta['ordered'] === $ordered
        ) {
            $itemToken      = $tokens[$i];
            $inlineChildren = $this->inlineParser->parse($itemToken->content, $this->linkRefs);
            $i++;

            // If the next token is a deeper-level list item, recurse.
            $nodeChildren = $inlineChildren;
            if ($i < $count
                && $tokens[$i]->type === TokenType::LIST_ITEM
                && $tokens[$i]->meta['depth'] > $depth
                && $depth < 32
            ) {
                $subList      = $this->buildList($tokens, $i, $tokens[$i]->meta['depth']);
                $nodeChildren = [...$inlineChildren, $subList];
            }

            $items[] = new ListItemNode(children: $nodeChildren, checked: $itemToken->meta['checked'] ?? null);
        }

        return new ListNode(ordered: $ordered, children: $items);
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
                    children: $this->inlineParser->parse($cell, $this->linkRefs),
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
                        children: $this->inlineParser->parse($cell, $this->linkRefs),
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
     * Scans tokens for LINK_DEFINITION entries, builds the reference map,
     * and returns the filtered token list (LINK_DEFINITION tokens removed).
     *
     * @param  Token[]  $tokens
     * @return array{refs: array<string, array{href: string, title: ?string}>, tokens: Token[]}
     */
    private function extractLinkDefinitions(array $tokens): array
    {
        $refs = [];
        $filtered = [];
        foreach ($tokens as $token) {
            if ($token->type === TokenType::LINK_DEFINITION) {
                $key = mb_strtolower($token->meta['label'], 'UTF-8');
                // First definition wins (CommonMark spec §4.7)
                if (!isset($refs[$key])) {
                    $refs[$key] = [
                        'href'  => $token->meta['href'],
                        'title' => $token->meta['title'],
                    ];
                }
            } else {
                $filtered[] = $token;
            }
        }
        return ['refs' => $refs, 'tokens' => $filtered];
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
                children: $this->inlineParser->parse(implode(' ', $lines), $this->linkRefs),
            );
        }
        return new BlockquoteNode(children: $paragraphs);
    }
}
