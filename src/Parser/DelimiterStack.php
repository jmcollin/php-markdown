<?php

declare(strict_types=1);

namespace PhpMarkdown\Parser;

use PhpMarkdown\Node\Inline\EmphasisNode;
use PhpMarkdown\Node\Inline\StrongNode;
use PhpMarkdown\Node\Inline\TextNode;
use PhpMarkdown\Node\InlineNodeInterface;

/**
 * Resolves a mixed token list (DelimiterRun + InlineNodeInterface) into a pure
 * InlineNodeInterface[] by applying the CommonMark Appendix A delimiter stack
 * algorithm ("process emphasis").
 *
 * Complexity: linear in the number of tokens. Tokens live in a doubly linked
 * list (integer ids), delimiters in a second linked list, and the openers_bottom
 * table bounds every backward search so no region is rescanned for the same
 * closer kind. Wrapping a span into <em>/<strong> relinks the span in O(1).
 *
 * Nesting is capped at MAX_NESTING: deeper emphasis pairs are emitted as literal
 * delimiter text, which keeps the AST (and the recursive renderer) shallow.
 */
final class DelimiterStack
{
    /** Maximum emphasis/strong nesting depth kept as nodes. */
    private const MAX_NESTING = 64;

    /** @var array<int, InlineNodeInterface> plain inline nodes, by id */
    private array $node = [];

    /** @var array<int, array{char: string, count: int, orig: int, canOpen: bool, canClose: bool}> */
    private array $delim = [];

    /** @var array<int, array{strong: bool, first: int, char: string}> emphasis wrappers, by id */
    private array $wrap = [];

    /** @var array<int, ?int> token list links */
    private array $prev = [];

    /** @var array<int, ?int> */
    private array $next = [];

    /** @var array<int, ?int> delimiter stack links */
    private array $dPrev = [];

    /** @var array<int, ?int> */
    private array $dNext = [];

    /** @var array<int, bool> */
    private array $inStack = [];

    /** @var array<string, ?int> openers_bottom, keyed by closer char/canOpen/orig%3 */
    private array $openersBottom = [];

    private int $nextId = 0;

    private int $tail = 0;

    /**
     * @param list<DelimiterRun|InlineNodeInterface> $tokens
     * @return InlineNodeInterface[]
     */
    public function resolve(array $tokens): array
    {
        $this->node = $this->delim = $this->wrap = [];
        $this->prev = $this->next = $this->dPrev = $this->dNext = [];
        $this->inStack = $this->openersBottom = [];

        // Sentinels: id 0 = head, id count+1 = tail.
        $head       = 0;
        $this->tail = count($tokens) + 1;
        $this->nextId = $this->tail + 1;
        $this->prev[$head] = null;
        $this->next[$head] = $this->tail;
        $this->prev[$this->tail] = $head;
        $this->next[$this->tail] = null;

        $lastId    = $head;
        $lastDelim = null;
        $firstDelim = null;
        foreach ($tokens as $idx => $token) {
            $id = $idx + 1;
            $this->prev[$id]     = $lastId;
            $this->next[$id]     = $this->tail;
            $this->next[$lastId] = $id;
            $this->prev[$this->tail] = $id;
            $lastId = $id;

            if ($token instanceof DelimiterRun) {
                $this->delim[$id] = [
                    'char'     => $token->char,
                    'count'    => $token->count,
                    'orig'     => $token->count,
                    'canOpen'  => $token->canOpen,
                    'canClose' => $token->canClose,
                ];
                $this->dPrev[$id]   = $lastDelim;
                $this->dNext[$id]   = null;
                $this->inStack[$id] = true;
                if ($lastDelim !== null) {
                    $this->dNext[$lastDelim] = $id;
                } else {
                    $firstDelim = $id;
                }
                $lastDelim = $id;
            } else {
                $this->node[$id] = $token;
            }
        }

        $this->processEmphasis($firstDelim);

        return $this->build($this->next[$head], 0);
    }

    private function processEmphasis(?int $closer): void
    {
        while ($closer !== null) {
            $c = $this->delim[$closer];
            if (!$c['canClose']) {
                $closer = $this->dNext[$closer];
                continue;
            }

            $key    = $c['char'] . ($c['canOpen'] ? '1' : '0') . ($c['orig'] % 3);
            $bottom = $this->openersBottom[$key] ?? null;

            // Look back for a matching opener, stopping at openers_bottom (exclusive).
            $opener = $this->dPrev[$closer];
            while ($opener !== null && $opener !== $bottom) {
                $o = $this->delim[$opener];
                if ($o['char'] === $c['char'] && $o['canOpen']) {
                    // CommonMark rule 9/10 ("multiple of 3"), on original run lengths.
                    $oddMatch = ($c['canOpen'] || $o['canClose'])
                        && $c['orig'] % 3 !== 0
                        && ($o['orig'] + $c['orig']) % 3 === 0;
                    if (!$oddMatch) {
                        break;
                    }
                }
                $opener = $this->dPrev[$opener];
            }

            if ($opener === null || $opener === $bottom || $this->next[$opener] === $closer) {
                // No opener: nothing at or below the element before this closer can open it.
                $this->openersBottom[$key] = $this->dPrev[$closer];
                $nextCloser = $this->dNext[$closer];
                if (!$c['canOpen']) {
                    $this->removeDelimiter($closer);
                }
                $closer = $nextCloser;
                continue;
            }

            $o   = $this->delim[$opener];
            $use = ($o['count'] >= 2 && $c['count'] >= 2) ? 2 : 1;
            $this->delim[$opener]['count'] -= $use;
            $this->delim[$closer]['count'] -= $use;

            // Delimiters strictly between opener and closer leave the stack (they stay
            // in the token list and are emitted as literal text).
            for ($d = $this->dNext[$opener]; $d !== null && $d !== $closer; $d = $this->dNext[$d]) {
                $this->inStack[$d] = false;
            }
            $this->dNext[$opener] = $closer;
            $this->dPrev[$closer] = $opener;
            foreach ($this->openersBottom as $k => $b) {
                if ($b !== null && !$this->inStack[$b]) {
                    // Known-no region is downward closed: opener is the nearest survivor below.
                    $this->openersBottom[$k] = $opener;
                }
            }

            // Wrap the token span (opener, closer) into a new emphasis node — O(1) relink.
            $first = (int) $this->next[$opener];
            $last  = (int) $this->prev[$closer];
            $w     = $this->nextId++;
            $this->wrap[$w]    = ['strong' => $use === 2, 'first' => $first, 'char' => $c['char']];
            $this->prev[$first] = null;
            $this->next[$last]  = null;
            $this->next[$opener] = $w;
            $this->prev[$w]      = $opener;
            $this->next[$w]      = $closer;
            $this->prev[$closer] = $w;

            if ($this->delim[$opener]['count'] === 0) {
                $this->unlink($opener);
                $this->removeDelimiter($opener);
            }
            if ($this->delim[$closer]['count'] === 0) {
                $nextCloser = $this->dNext[$closer];
                $this->unlink($closer);
                $this->removeDelimiter($closer);
                $closer = $nextCloser;
            }
        }
    }

    /** Remove a token from the token list. */
    private function unlink(int $id): void
    {
        $p = $this->prev[$id];
        $n = $this->next[$id];
        if ($p !== null) {
            $this->next[$p] = $n;
        }
        if ($n !== null) {
            $this->prev[$n] = $p;
        }
    }

    /** Remove a delimiter from the delimiter stack, retargeting openers_bottom entries. */
    private function removeDelimiter(int $id): void
    {
        $p = $this->dPrev[$id];
        $n = $this->dNext[$id];
        if ($p !== null) {
            $this->dNext[$p] = $n;
        }
        if ($n !== null) {
            $this->dPrev[$n] = $p;
        }
        $this->inStack[$id] = false;
        foreach ($this->openersBottom as $k => $b) {
            if ($b === $id) {
                $this->openersBottom[$k] = $p;
            }
        }
    }

    /**
     * Convert a token sub-list (starting at $id) into inline nodes.
     *
     * @return InlineNodeInterface[]
     */
    private function build(?int $id, int $depth): array
    {
        /** @var list<DelimiterRun|InlineNodeInterface> $items */
        $items = [];
        for (; $id !== null && $id !== $this->tail; $id = $this->next[$id]) {
            if (isset($this->wrap[$id])) {
                $wrap = $this->wrap[$id];
                if ($depth >= self::MAX_NESTING) {
                    $this->flattenInto($id, $items);
                    continue;
                }
                $children = $this->build($wrap['first'], $depth + 1);
                $items[]  = $wrap['strong'] ? new StrongNode($children) : new EmphasisNode($children);
            } elseif (isset($this->delim[$id])) {
                $items[] = $this->literalRun($id);
            } else {
                $items[] = $this->node[$id];
            }
        }
        return $this->mergeText($items);
    }

    /**
     * Emit a wrapper and everything below it as flat items, delimiters as literal
     * text. Iterative (explicit stack) so arbitrarily deep input cannot overflow.
     *
     * @param list<DelimiterRun|InlineNodeInterface> $items
     */
    private function flattenInto(int $wrapId, array &$items): void
    {
        /** @var list<array{0: 'lit', 1: DelimiterRun}|array{0: 'list', 1: ?int}> $work */
        $work = [];
        $this->pushWrapper($wrapId, $work);
        while ($work !== []) {
            $item = array_pop($work);
            if ($item[0] === 'lit') {
                $items[] = $item[1];
                continue;
            }
            $id = $item[1];
            if ($id === null) {
                continue;
            }
            $work[] = ['list', $this->next[$id]];
            if (isset($this->wrap[$id])) {
                $this->pushWrapper($id, $work);
            } elseif (isset($this->delim[$id])) {
                $items[] = $this->literalRun($id);
            } else {
                $items[] = $this->node[$id];
            }
        }
    }

    /**
     * Push open delimiter, children and close delimiter (in pop order) for a wrapper.
     *
     * @param list<array{0: 'lit', 1: DelimiterRun}|array{0: 'list', 1: ?int}> $work
     */
    private function pushWrapper(int $wrapId, array &$work): void
    {
        $wrap = $this->wrap[$wrapId];
        $run  = new DelimiterRun($wrap['char'], $wrap['strong'] ? 2 : 1, false, false);
        $work[] = ['lit', $run];
        $work[] = ['list', $wrap['first']];
        $work[] = ['lit', $run];
    }

    private function literalRun(int $id): DelimiterRun
    {
        $d = $this->delim[$id];
        return new DelimiterRun($d['char'], $d['count'], $d['canOpen'], $d['canClose']);
    }

    /**
     * Convert remaining DelimiterRuns into TextNodes.
     * Unmatched runs are literal chars that must join adjacent text on both sides
     * (e.g. "**unclosed" → TextNode("**unclosed")). However, TextNodes produced by
     * backslash escapes must remain distinct — only merge when the boundary is a
     * DelimiterRun conversion, tracked by $lastWasDelimiter.
     *
     * @param list<DelimiterRun|InlineNodeInterface> $tokens
     * @return InlineNodeInterface[]
     */
    private function mergeText(array $tokens): array
    {
        // $buf holds the trailing TextNode's text until something else is appended,
        // so long chains of merges stay linear (no repeated string re-copying).
        $result = [];
        $buf    = null;
        $lastWasDelimiter = false;
        foreach ($tokens as $token) {
            if ($token instanceof DelimiterRun) {
                $buf ??= '';
                $buf  .= str_repeat($token->char, $token->count);
                $lastWasDelimiter = true;
                continue;
            }
            if ($token instanceof TextNode) {
                if ($lastWasDelimiter && $buf !== null) {
                    $buf .= $token->text;
                } else {
                    if ($buf !== null) {
                        $result[] = new TextNode($buf);
                    }
                    $buf = $token->text;
                }
                $lastWasDelimiter = false;
                continue;
            }
            if ($buf !== null) {
                $result[] = new TextNode($buf);
                $buf = null;
            }
            $result[] = $token;
            $lastWasDelimiter = false;
        }
        if ($buf !== null) {
            $result[] = new TextNode($buf);
        }

        return $result;
    }
}
