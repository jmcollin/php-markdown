<?php

declare(strict_types=1);

namespace PhpMarkdown\Sanitizer;

final class HtmlSanitizer
{
    /** @var string[] */
    private const SAFE_BLOCK_TAGS = [
        'div', 'p', 'blockquote', 'pre', 'ul', 'ol', 'li',
        'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'section', 'article', 'aside', 'header', 'footer',
        'figure', 'figcaption', 'details', 'summary',
    ];

    /** @var string[] */
    private const SAFE_INLINE_TAGS = [
        'a', 'abbr', 'b', 'br', 'cite', 'code', 'del', 'em', 'i', 'img',
        'ins', 'kbd', 'mark', 'q', 's', 'samp', 'small', 'span', 'strong',
        'sub', 'sup', 'time', 'u', 'var',
    ];

    /** @var string[] */
    private const FORBIDDEN_TAGS = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed',
        'applet', 'base', 'form', 'input', 'button', 'textarea', 'select',
        'link', 'meta', 'noscript', 'template', 'slot', 'canvas', 'svg', 'math',
    ];

    /** @var string[] */
    private const SAFE_TAGS = [...self::SAFE_BLOCK_TAGS, ...self::SAFE_INLINE_TAGS];

    /** @var string[] */
    private const SAFE_GLOBAL_ATTRS = ['class', 'id', 'title', 'lang', 'dir'];

    /** @var array<string, string[]> */
    private const SAFE_TAG_ATTRS = [
        'a'       => ['href', 'title', 'rel', 'target'],
        'img'     => ['src', 'alt', 'title', 'width', 'height'],
        'td'      => ['colspan', 'rowspan', 'align'],
        'th'      => ['colspan', 'rowspan', 'align'],
        'ol'      => ['start', 'type'],
        'li'      => ['value'],
        'details' => ['open'],
        'time'    => ['datetime'],
    ];

    /** @var string[] */
    private const URL_ATTRS = ['href', 'src'];

    /** @var string[] */
    private const SAFE_URL_SCHEMES = ['http', 'https', 'mailto', ''];

    public function sanitize(string $html): string
    {
        if ($html === '') {
            return '';
        }

        libxml_use_internal_errors(true);

        $dom = new \DOMDocument();
        $prefixed = '<meta charset="UTF-8">' . $html;

        $loaded = $dom->loadHTML(
            $prefixed,
            LIBXML_NOERROR | LIBXML_HTML_NODEFDTD
        );

        libxml_clear_errors();

        if ($loaded === false) {
            return '';
        }

        $this->walkNode($dom);

        // Serialize: prefer body children to avoid html/body wrappers
        $body = $dom->getElementsByTagName('body')->item(0);

        $output = '';

        if ($body !== null) {
            foreach (iterator_to_array($body->childNodes) as $child) {
                $output .= $dom->saveHTML($child);
            }
        } else {
            // Fallback for flat-text input with no body wrapper
            foreach (iterator_to_array($dom->childNodes) as $child) {
                $serialized = $dom->saveHTML($child);
                if (str_starts_with(ltrim($serialized), '<meta')) {
                    continue;
                }
                $output .= $serialized;
            }
        }

        // Strip the injected charset meta tag (defensive, in case it ended up in output)
        $output = str_replace('<meta charset="UTF-8">', '', $output);

        return trim($output);
    }

    private function walkNode(\DOMNode $node): void
    {
        // Collect phase — snapshot children before any mutation
        $children = iterator_to_array($node->childNodes);

        $toRemoveComments    = [];
        $toRemoveWithContent = [];
        $toSanitizeAttrs     = [];
        $toUnwrap            = [];

        foreach ($children as $child) {
            if ($child instanceof \DOMComment) {
                $toRemoveComments[] = $child;
                continue;
            }

            if (!($child instanceof \DOMElement)) {
                // DOMText and other nodes: preserve verbatim
                continue;
            }

            $tagName = strtolower($child->tagName);

            if (in_array($tagName, self::FORBIDDEN_TAGS, true)) {
                $toRemoveWithContent[] = $child;
            } elseif (in_array($tagName, self::SAFE_TAGS, true)) {
                $toSanitizeAttrs[] = $child;
            } else {
                $toUnwrap[] = $child;
            }
        }

        // Mutation phase

        // Comments: remove unconditionally (security vector)
        foreach ($toRemoveComments as $comment) {
            if ($comment->parentNode !== null) {
                $comment->parentNode->removeChild($comment);
            }
        }

        // Category A — forbidden: remove tag + entire subtree
        foreach ($toRemoveWithContent as $element) {
            if ($element->parentNode !== null) {
                $element->parentNode->removeChild($element);
            }
        }

        // Category C — unknown: recurse first, then unwrap (promote children to parent)
        foreach ($toUnwrap as $element) {
            if ($element->parentNode === null) {
                continue;
            }
            $this->walkNode($element);
            $parent = $element->parentNode;
            while ($element->firstChild !== null) {
                $parent->insertBefore($element->firstChild, $element);
            }
            $parent->removeChild($element);
        }

        // Category B — safe: sanitize attributes then recurse
        foreach ($toSanitizeAttrs as $element) {
            $this->sanitizeAttributes($element);
            $this->walkNode($element);
        }
    }

    private function sanitizeAttributes(\DOMElement $el): void
    {
        // Collect phase — snapshot attribute nodes before any mutation
        // @phpstan-ignore identical.alwaysFalse
        if ($el->attributes === null) {
            return;
        }
        $attrs = iterator_to_array($el->attributes);

        foreach ($attrs as $attrNode) {
            $name   = $attrNode->name;
            $lcName = strtolower($name);

            // Always-reject patterns
            if (
                str_starts_with($lcName, 'on')
                || $lcName === 'style'
                || $lcName === 'xmlns'
                || str_starts_with($lcName, 'xlink:')
            ) {
                $el->removeAttributeNode($attrNode);
                continue;
            }

            $tagName = strtolower($el->tagName);

            // Determine if allowed
            $isGlobal  = in_array($lcName, self::SAFE_GLOBAL_ATTRS, true);
            $isAria    = str_starts_with($lcName, 'aria-');
            $tagExtras = self::SAFE_TAG_ATTRS[$tagName] ?? [];
            $isTagAttr = in_array($lcName, $tagExtras, true);

            if (!$isGlobal && !$isAria && !$isTagAttr) {
                $el->removeAttributeNode($attrNode);
                continue;
            }

            // URL validation for href and src
            if (in_array($lcName, self::URL_ATTRS, true)) {
                if (!$this->isSafeUrl($attrNode->value)) {
                    $el->removeAttributeNode($attrNode);
                    continue;
                }
            }

            // target enforcement: only _blank is allowed
            if ($lcName === 'target' && $attrNode->value !== '_blank') {
                $el->removeAttributeNode($attrNode);
                continue;
            }
        }

        // Post-loop pass — noopener injection (runs after all attribute removals)
        if ($el->hasAttribute('target') && $el->getAttribute('target') === '_blank') {
            $existing = $el->getAttribute('rel');
            $tokens   = $existing !== '' ? (preg_split('/\s+/', trim($existing)) ?: []) : [];
            if (!in_array('noopener', array_map('strtolower', $tokens), true)) {
                $tokens[] = 'noopener';
            }
            $el->setAttribute('rel', implode(' ', $tokens));
        }
    }

    private function isSafeUrl(string $url): bool
    {
        // Step 1: decode HTML entities
        $decoded = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Step 2: percent-decode
        $decoded = rawurldecode($decoded);

        // Step 3: reject control characters (including null byte)
        if (preg_match('/[\x00-\x20\x7F]/', $decoded) === 1) {
            return false;
        }

        // Step 4: reject protocol-relative and backslash-prefixed URLs
        if (str_starts_with($decoded, '//') || str_starts_with($decoded, '\\')) {
            return false;
        }

        // Step 5: parse the URL
        $parts = parse_url($decoded);
        if ($parts === false) {
            return false;
        }

        // Step 6: extract and normalize scheme
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';

        // Step 7: check against allowlist
        return in_array($scheme, self::SAFE_URL_SCHEMES, true);
    }
}
