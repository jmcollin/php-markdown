<?php

declare(strict_types=1);

namespace PhpMarkdown\Parser;

use PhpMarkdown\Exception\ParseException;
use PhpMarkdown\Lexer\Lexer;
use PhpMarkdown\Lexer\Token;
use PhpMarkdown\Lexer\TokenType;
use PhpMarkdown\Node\Block\BlockquoteNode;
use PhpMarkdown\Node\Block\ColumnsNode;
use PhpMarkdown\Node\Block\DocumentNode;
use PhpMarkdown\Node\Block\FencedCodeNode;
use PhpMarkdown\Node\Block\IndentedCodeNode;
use PhpMarkdown\Node\Block\HeadingNode;
use PhpMarkdown\Node\Block\HorizontalRuleNode;
use PhpMarkdown\Node\Block\ListItemNode;
use PhpMarkdown\Node\Block\ListNode;
use PhpMarkdown\Node\Block\ParagraphNode;
use PhpMarkdown\Node\Block\RawHtmlBlockNode;
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

        return new DocumentNode($this->parseBlocks($tokens));
    }

    /**
     * Build a block-level AST from an already-filtered Token[].
     * Uses $this->linkRefs implicitly (set by parse() before this is called).
     *
     * @param  Token[] $tokens
     * @return array<int, \PhpMarkdown\Node\BlockNodeInterface>
     */
    private function parseBlocks(array $tokens): array
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

            if ($token->type === TokenType::INDENTED_CODE) {
                $children[] = new IndentedCodeNode(content: $token->content);
                $i++;
                continue;
            }

            if ($token->type === TokenType::HORIZONTAL_RULE) {
                $children[] = new HorizontalRuleNode();
                $i++;
                continue;
            }

            if ($token->type === TokenType::HTML_BLOCK) {
                $children[] = new RawHtmlBlockNode(content: $token->content);
                $i++;
                continue;
            }

            if ($token->type === TokenType::BLOCKQUOTE) {
                $children[] = $this->buildBlockquote($tokens, $i);
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

            if ($token->type === TokenType::COLUMNS_CONTAINER) {
                $children[] = $this->buildColumns($token);
                $i++;
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

        return $children;
    }

    /**
     * Build a ColumnsNode from a COLUMNS_CONTAINER token.
     * Re-lexes each raw column segment and calls parseBlocks() on each result.
     * Any nested COLUMNS_CONTAINER tokens are replaced with PARAGRAPH tokens
     * containing the literal text ":::columns" to enforce flat-only nesting.
     */
    private function buildColumns(Token $token): ColumnsNode
    {
        $lexer = new Lexer();

        $leftTokens  = $this->flattenColumnsTokens($lexer->tokenize($token->meta['left_raw']));
        $rightTokens = $this->flattenColumnsTokens($lexer->tokenize($token->meta['right_raw']));

        return new ColumnsNode(
            leftChildren:  $this->parseBlocks($leftTokens),
            rightChildren: $this->parseBlocks($rightTokens),
        );
    }

    /**
     * Replace any COLUMNS_CONTAINER tokens with PARAGRAPH tokens containing ":::columns"
     * to enforce flat-only nesting per the story spec.
     *
     * @param  Token[] $tokens
     * @return Token[]
     */
    private function flattenColumnsTokens(array $tokens): array
    {
        return array_map(
            static fn(Token $t): Token => $t->type === TokenType::COLUMNS_CONTAINER
                ? new Token(TokenType::PARAGRAPH, ':::columns')
                : $t,
            $tokens,
        );
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
                $result[] = ($token->meta['hard_break'] ?? false) === true
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
        $loose   = false;

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

            // Peek ahead for blank tokens. Consume only when the token after the blank run
            // is a LIST_ITEM at the same depth and ordered value (inter-item blanks).
            $j = $i;
            while ($j < $count && $tokens[$j]->type === TokenType::BLANK) {
                $j++;
            }
            if ($j > $i
                && $j < $count
                && $tokens[$j]->type === TokenType::LIST_ITEM
                && $tokens[$j]->meta['depth'] === $depth
                && $tokens[$j]->meta['ordered'] === $ordered
            ) {
                $i     = $j;
                $loose = true;
            }

            $items[] = new ListItemNode(children: $nodeChildren, checked: $itemToken->meta['checked'] ?? null);
        }

        // Propagate loose to all items now that the final value is known.
        if ($loose) {
            $items = array_map(
                static fn(ListItemNode $item) => new ListItemNode(
                    children: $item->children,
                    loose:    true,
                    checked:  $item->checked,
                ),
                $items,
            );
        }

        return new ListNode(ordered: $ordered, loose: $loose, children: $items);
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
     * Recursively builds a nested BlockquoteNode from a flat stream of BLOCKQUOTE tokens.
     *
     * $i is passed by reference so that a level-decrease early-return communicates the
     * cursor position back to the caller, mirroring the buildList() contract.
     *
     * @param Token[] $tokens
     */
    private function buildBlockquote(array $tokens, int &$i, int $minLevel = 1): BlockquoteNode
    {
        $count    = count($tokens);
        $children = [];
        $buffer   = [];

        while ($i < $count && $tokens[$i]->type === TokenType::BLOCKQUOTE) {
            $level = $tokens[$i]->meta['level'];

            if ($level < $minLevel) {
                // Level decrease: flush buffer and yield cursor to caller.
                if ($buffer !== []) {
                    $children[] = new ParagraphNode(
                        children: $this->inlineParser->parse(implode(' ', $buffer), $this->linkRefs),
                    );
                }
                return new BlockquoteNode(children: $children);
            }

            if ($level === $minLevel) {
                if ($tokens[$i]->content !== '') {
                    $buffer[] = $tokens[$i]->content;
                }
                $i++;

                // Flush buffer when the next token changes level or ends the blockquote run.
                $nextIsCurrentLevel = $i < $count
                    && $tokens[$i]->type === TokenType::BLOCKQUOTE
                    && $tokens[$i]->meta['level'] === $minLevel;

                if (!$nextIsCurrentLevel && $buffer !== []) {
                    $children[] = new ParagraphNode(
                        children: $this->inlineParser->parse(implode(' ', $buffer), $this->linkRefs),
                    );
                    $buffer = [];
                }
                continue;
            }

            // $level > $minLevel: flush buffer then recurse.
            if ($buffer !== []) {
                $children[] = new ParagraphNode(
                    children: $this->inlineParser->parse(implode(' ', $buffer), $this->linkRefs),
                );
                $buffer = [];
            }

            if ($minLevel < 32) {
                $children[] = $this->buildBlockquote($tokens, $i, $minLevel + 1);
            } else {
                // Depth guard (max 32 levels, matching buildList). Tokens beyond this depth
                // are intentionally rendered as content inside the level-32 blockquote rather
                // than triggering unbounded recursion. The extra ">" markers are consumed and
                // discarded; only the text content is preserved.
                if ($tokens[$i]->content !== '') {
                    $buffer[] = $tokens[$i]->content;
                }
                $i++;
            }
        }

        // Flush any remaining buffer at end of token stream.
        if ($buffer !== []) {
            $children[] = new ParagraphNode(
                children: $this->inlineParser->parse(implode(' ', $buffer), $this->linkRefs),
            );
        }

        return new BlockquoteNode(children: $children);
    }
}
