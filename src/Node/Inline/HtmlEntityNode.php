<?php

declare(strict_types=1);

namespace PhpMarkdown\Node\Inline;

use PhpMarkdown\Node\InlineNodeInterface;

/**
 * An HTML entity decoded to its Unicode character, HTML-escaped for safe verbatim output.
 *
 * InlineParser::scan() resolves the raw entity reference (named or numeric) to the
 * Unicode character(s) it represents, then applies htmlspecialchars() so that characters
 * that are meaningful in HTML (e.g. & < >) are re-encoded as HTML entities while all
 * other characters are stored as literal UTF-8.
 *
 * Examples (raw entity → stored value):
 *   &amp;   → &amp;      (& re-encoded)
 *   &copy;  → ©          (literal UTF-8)
 *   &#160;  → \xC2\xA0   (literal non-breaking space)
 *   &#0;    → \xEF\xBF\xBD  (U+FFFD replacement character)
 *
 * SECURITY: the renderer MUST NOT call esc() on the entity string — it is already safe.
 */
final readonly class HtmlEntityNode implements InlineNodeInterface
{
    public function __construct(
        public string $entity,
    ) {
    }
}
