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
 * algorithm.
 */
final class DelimiterStack
{
    /**
     * @param list<DelimiterRun|InlineNodeInterface> $tokens
     * @return InlineNodeInterface[]
     */
    public function resolve(array $tokens): array
    {
        $current = 0;

        while ($current < count($tokens)) {
            // Find the next closing delimiter.
            $closerIdx = null;
            for ($i = $current; $i < count($tokens); $i++) {
                $t = $tokens[$i];
                if ($t instanceof DelimiterRun && $t->canClose) {
                    $closerIdx = $i;
                    break;
                }
            }

            if ($closerIdx === null) {
                // No more closers — done.
                break;
            }

            $closer = $tokens[$closerIdx];
            assert($closer instanceof DelimiterRun);

            // Scan backward for a matching opener.
            $openerIdx = null;
            for ($i = $closerIdx - 1; $i >= 0; $i--) {
                $t = $tokens[$i];
                if (!($t instanceof DelimiterRun)) {
                    continue;
                }
                if (!$t->canOpen || $t->char !== $closer->char) {
                    continue;
                }
                // CommonMark rule 11: if either delimiter can both open and close,
                // the sum of lengths mod 3 must not be 0 unless both are multiples of 3.
                if ($t->canClose || $closer->canOpen) {
                    if (($t->count + $closer->count) % 3 === 0
                        && !($t->count % 3 === 0 && $closer->count % 3 === 0)
                    ) {
                        continue;
                    }
                }
                $openerIdx = $i;
                break;
            }

            if ($openerIdx === null) {
                // No opener found — advance past this closer.
                $current = $closerIdx + 1;
                continue;
            }

            $opener = $tokens[$openerIdx];
            assert($opener instanceof DelimiterRun);

            // Collect inner tokens (exclusive).
            $inner = array_slice($tokens, $openerIdx + 1, $closerIdx - $openerIdx - 1);

            // Empty inner span — skip this pair to avoid <em></em>.
            if (count($inner) === 0) {
                $current = $closerIdx + 1;
                continue;
            }

            // Resolve inner tokens recursively (they may contain unresolved runs).
            $innerResolved = $this->resolve($inner);

            // Match length: prefer 2 (strong) when both sides have >= 2 chars.
            $matchLen = (min($opener->count, $closer->count) >= 2) ? 2 : 1;

            $newNode = $matchLen === 2
                ? new StrongNode($innerResolved)
                : new EmphasisNode($innerResolved);

            // Build the replacement slice.
            $replacement = [];

            $openerRemain = $opener->count - $matchLen;
            if ($openerRemain > 0) {
                $replacement[] = new DelimiterRun($opener->char, $openerRemain, $opener->canOpen, $opener->canClose);
            }
            $replacement[] = $newNode;

            $closerRemain = $closer->count - $matchLen;
            if ($closerRemain > 0) {
                $replacement[] = new DelimiterRun($closer->char, $closerRemain, $closer->canOpen, $closer->canClose);
            }

            array_splice($tokens, $openerIdx, $closerIdx - $openerIdx + 1, $replacement);

            // Recalculate current: step back to re-examine the area around the splice.
            $current = max(0, $openerIdx - 1);
        }

        // Convert remaining DelimiterRuns into TextNodes, then merge adjacent TextNodes.
        $result = [];
        foreach ($tokens as $token) {
            if ($token instanceof DelimiterRun) {
                $literal = str_repeat($token->char, $token->count);
                $last = end($result);
                if ($last instanceof TextNode) {
                    array_pop($result);
                    $result[] = new TextNode($last->text . $literal);
                } else {
                    $result[] = new TextNode($literal);
                }
            } elseif ($token instanceof TextNode) {
                $last = end($result);
                if ($last instanceof TextNode) {
                    array_pop($result);
                    $result[] = new TextNode($last->text . $token->text);
                } else {
                    $result[] = $token;
                }
            } else {
                $result[] = $token;
            }
        }

        return $result;
    }
}
