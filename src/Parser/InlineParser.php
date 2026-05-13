<?php

declare(strict_types=1);

namespace PhpMarkdown\Parser;

use PhpMarkdown\Node\Inline\CodeNode;
use PhpMarkdown\Node\Inline\EmphasisNode;
use PhpMarkdown\Node\Inline\ImageNode;
use PhpMarkdown\Node\Inline\LinkNode;
use PhpMarkdown\Node\Inline\StrikethroughNode;
use PhpMarkdown\Node\Inline\StrongNode;
use PhpMarkdown\Node\Inline\TextNode;
use PhpMarkdown\Node\InlineNodeInterface;

/**
 * Parses inline Markdown syntax into a sequence of InlineNode objects.
 *
 * Stateless recursive scanner: handles nested emphasis/strong correctly.
 */
final class InlineParser
{
    private const SAFE_SCHEMES = ['http', 'https', 'mailto', ''];

    /** Maximum nesting depth for recursive inline parsing (prevents stack overflow). */
    private const MAX_DEPTH = 64;

    // Patterns use \G + offset param instead of substr() to avoid O(n²) string copies.
    // URL capture class excludes < > to block <javascript:...> autolink-style bypass.
    private const PATTERN_IMAGE = '/\G!\[([^\]]*)\]\(([^)<>"\s]+)(?:\s+"([^"]*)")?\)/';
    private const PATTERN_LINK  = '/\G\[([^\]]+)\]\(([^)<>"\s]+)(?:\s+"([^"]*)")?\)/';

    /**
     * @return InlineNodeInterface[]
     */
    public function parse(string $text): array
    {
        return $this->scan($text, 0);
    }

    /**
     * @return InlineNodeInterface[]
     */
    private function scan(string $text, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            return $text !== '' ? [new TextNode($text)] : [];
        }

        $nodes = [];
        $len = strlen($text);
        $pos = 0;
        $buffer = '';

        while ($pos < $len) {
            $char = $text[$pos];

            // ── Inline code: `code` or ``code`` ──────────────────────────────
            if ($char === '`') {
                $btCount = 0;
                $tmp = $pos;
                while ($tmp < $len && $text[$tmp] === '`') {
                    $btCount++;
                    $tmp++;
                }
                $closer = str_repeat('`', $btCount);
                $closePos = strpos($text, $closer, $tmp);
                if ($closePos !== false) {
                    $nodes = $this->flushBuffer($buffer, $nodes);
                    $buffer = '';
                    $nodes[] = new CodeNode(trim(substr($text, $tmp, $closePos - $tmp)));
                    $pos = $closePos + $btCount;
                    continue;
                }
                $buffer .= $char;
                $pos++;
                continue;
            }

            // ── Image: ![alt](src "title"?) ───────────────────────────────────
            if ($char === '!' && ($pos + 1) < $len && $text[$pos + 1] === '[') {
                if (preg_match(self::PATTERN_IMAGE, $text, $m, 0, $pos)) {
                    $nodes = $this->flushBuffer($buffer, $nodes);
                    $buffer = '';
                    if ($this->isSafeUrl($m[2])) {
                        $nodes[] = new ImageNode(
                            src: $m[2],
                            alt: $m[1],
                            title: isset($m[3]) && $m[3] !== '' ? $m[3] : null,
                        );
                    } else {
                        $buffer .= $m[0]; // Unsafe src: render as literal text (XSS prevention)
                    }
                    $pos += strlen($m[0]);
                    continue;
                }
            }

            // ── Link: [text](url "title"?) ────────────────────────────────────
            if ($char === '[') {
                if (preg_match(self::PATTERN_LINK, $text, $m, 0, $pos)) {
                    $nodes = $this->flushBuffer($buffer, $nodes);
                    $buffer = '';
                    $href = $m[2];
                    if ($this->isSafeUrl($href)) {
                        $title = isset($m[3]) && $m[3] !== '' ? $m[3] : null;
                        $nodes[] = new LinkNode(
                            href: $href,
                            children: $this->scan($m[1], $depth + 1),
                            title: $title,
                        );
                    } else {
                        $buffer .= $m[0]; // Unsafe URL: render as literal text (XSS prevention)
                    }
                    $pos += strlen($m[0]);
                    continue;
                }
            }

            // ── Strikethrough: ~~text~~ ──────────────────────────────────────
            if ($char === '~' && isset($text[$pos + 1]) && $text[$pos + 1] === '~') {
                $closePos = strpos($text, '~~', $pos + 2);
                if ($closePos !== false) {
                    $inner = substr($text, $pos + 2, $closePos - $pos - 2);
                    if ($inner !== '') {
                        $nodes = $this->flushBuffer($buffer, $nodes);
                        $buffer = '';
                        $nodes[] = new StrikethroughNode($this->scan($inner, $depth + 1));
                        $pos = $closePos + 2;
                        continue;
                    }
                }
                $buffer .= '~~';
                $pos += 2;
                continue;
            }

            // ── Strong: **text** or __text__ ──────────────────────────────────
            if (
                ($char === '*' && isset($text[$pos + 1]) && $text[$pos + 1] === '*') ||
                ($char === '_' && isset($text[$pos + 1]) && $text[$pos + 1] === '_')
            ) {
                $marker = $char . $char;
                $closePos = strpos($text, $marker, $pos + 2);
                if ($closePos !== false) {
                    $nodes = $this->flushBuffer($buffer, $nodes);
                    $buffer = '';
                    $inner = substr($text, $pos + 2, $closePos - $pos - 2);
                    $nodes[] = new StrongNode($this->scan($inner, $depth + 1));
                    $pos = $closePos + 2;
                    continue;
                }
                $buffer .= $marker;
                $pos += 2;
                continue;
            }

            // ── Emphasis: *text* or _text_ ────────────────────────────────────
            if ($char === '*' || $char === '_') {
                $closePos = strpos($text, $char, $pos + 1);
                if ($closePos !== false) {
                    $nodes = $this->flushBuffer($buffer, $nodes);
                    $buffer = '';
                    $inner = substr($text, $pos + 1, $closePos - $pos - 1);
                    $nodes[] = new EmphasisNode($this->scan($inner, $depth + 1));
                    $pos = $closePos + 1;
                    continue;
                }
                $buffer .= $char;
                $pos++;
                continue;
            }

            $buffer .= $char;
            $pos++;
        }

        return $this->flushBuffer($buffer, $nodes);
    }

    /**
     * @param InlineNodeInterface[] $nodes
     * @return InlineNodeInterface[]
     */
    private function flushBuffer(string $buffer, array $nodes): array
    {
        if ($buffer !== '') {
            $nodes[] = new TextNode($buffer);
        }
        return $nodes;
    }

    private function isSafeUrl(string $url): bool
    {
        // Reject control chars and space — bypass vectors for parse_url scheme detection.
        if (preg_match('/[\x00-\x20\x7F]/', $url)) {
            return false;
        }
        // Reject protocol-relative URLs (//evil.com inherits the host page's scheme).
        if (str_starts_with($url, '//') || str_starts_with($url, '\\')) {
            return false;
        }
        // parse_url() returning false means malformed URL — reject rather than allow.
        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        return in_array($scheme, self::SAFE_SCHEMES, true);
    }
}
