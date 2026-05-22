<?php

declare(strict_types=1);

namespace PhpMarkdown\Node\Inline;

use PhpMarkdown\Node\InlineNodeInterface;

/**
 * A valid HTML entity that must be passed through verbatim to the renderer.
 * Examples: &amp;  &#160;  &#x00A0;
 *
 * SECURITY: only entities validated by InlineParser::scan() reach this node.
 * The renderer MUST NOT call esc() on the entity string.
 */
final readonly class HtmlEntityNode implements InlineNodeInterface
{
    public function __construct(
        public string $entity,
    ) {
    }
}
