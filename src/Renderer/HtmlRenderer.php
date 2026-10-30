<?php

declare(strict_types=1);

namespace PhpMarkdown\Renderer;

use PhpMarkdown\Node\Block\BlockquoteNode;
use PhpMarkdown\Node\Block\ColumnsNode;
use PhpMarkdown\Node\Block\DocumentNode;
use PhpMarkdown\Node\Block\FencedCodeNode;
use PhpMarkdown\Node\Block\FootnoteDefinitionNode;
use PhpMarkdown\Node\Block\FootnotesContainerNode;
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

use PhpMarkdown\Node\Inline\AutolinkNode;
use PhpMarkdown\Node\Inline\CodeNode;
use PhpMarkdown\Node\Inline\EmphasisNode;
use PhpMarkdown\Node\Inline\FootnoteRefNode;
use PhpMarkdown\Node\Inline\HardBreakNode;
use PhpMarkdown\Node\Inline\SoftBreakNode;
use PhpMarkdown\Node\Inline\HtmlEntityNode;
use PhpMarkdown\Node\Inline\ImageNode;
use PhpMarkdown\Node\Inline\LinkNode;
use PhpMarkdown\Node\Inline\RawHtmlInlineNode;
use PhpMarkdown\Node\Inline\StrikethroughNode;
use PhpMarkdown\Node\Inline\StrongNode;
use PhpMarkdown\Node\Inline\TextNode;
use PhpMarkdown\Node\NodeInterface;
use PhpMarkdown\Sanitizer\HtmlSanitizer;

/**
 * HTML5 renderer.
 *
 * XSS rule: every user-supplied string passes through esc() before output.
 * This includes TextNode text, code content, and all HTML attributes.
 * Raw HTML is escaped by default; pass allowRawHtml: true to enable sanitized pass-through.
 * In that mode, every inline run containing raw HTML (paragraph, heading, table cell,
 * list item text, footnote body) is rendered first, then passed as one fragment
 * through HtmlSanitizer, so tags split across several RawHtmlInlineNodes are
 * sanitized together.
 */
final class HtmlRenderer
{
    public function __construct(
        private readonly bool $allowRawHtml = false,
        private readonly ?string $linkTarget = null,
        private readonly ?string $linkRel = null,
        private readonly HtmlSanitizer $sanitizer = new HtmlSanitizer(),
    ) {
        $this->linkAttrs = $this->buildTargetAndRelAttrs();
    }

    /** target/rel attributes shared by every rendered link (computed once). */
    private readonly string $linkAttrs;

    public function render(DocumentNode $document): string
    {
        return $this->renderChildren($document->children);
    }

    private function renderNode(NodeInterface $node): string
    {
        // Constant class names compile to a hash lookup, unlike a chain of instanceof.
        // Exact match: all node classes are final.
        return match ($node::class) {
            HeadingNode::class      => $this->renderHeading($node),
            ParagraphNode::class    => '<p>' . $this->renderInline($node->children) . "</p>\n",
            BlockquoteNode::class   => "<blockquote>\n" . $this->renderChildren($node->children) . "</blockquote>\n",
            ListNode::class         => $this->renderList($node),
            ListItemNode::class     => $this->renderListItem($node),
            FencedCodeNode::class   => $this->renderFencedCode($node),
            IndentedCodeNode::class => '<pre><code>' . $this->esc($node->content) . "</code></pre>\n",
            HorizontalRuleNode::class => "<hr />\n",
            ColumnsNode::class       => $this->renderColumns($node),
            // Raw HTML blocks: sanitize if allowRawHtml, else escape (XSS-safe default).
            RawHtmlBlockNode::class =>
                $this->allowRawHtml
                    ? $this->sanitizer->sanitize($node->content) . "\n"
                    : $this->esc($node->content) . "\n",
            HardBreakNode::class    => "<br />\n",
            SoftBreakNode::class    => "\n",
            // HTML entities pass through verbatim — validated by InlineParser, no esc() needed.
            HtmlEntityNode::class   => $node->entity,
            TextNode::class         => $this->esc($node->text),
            StrongNode::class       => '<strong>' . $this->renderChildren($node->children) . '</strong>',
            StrikethroughNode::class => '<del>' . $this->renderChildren($node->children) . '</del>',
            EmphasisNode::class     => '<em>' . $this->renderChildren($node->children) . '</em>',
            CodeNode::class         => '<code>' . $this->esc($node->code) . '</code>',
            LinkNode::class         => $this->renderLink($node),
            ImageNode::class        => $this->renderImage($node),
            TableNode::class        => $this->renderTable($node),
            TableRowNode::class     => $this->renderTableRow($node),
            // Inline HTML: emitted as-is if allowRawHtml — the enclosing inline run is then
            // sanitized as a whole by renderInline() — else escaped.
            RawHtmlInlineNode::class =>
                $this->allowRawHtml
                    ? $node->content
                    : $this->esc($node->content),
            AutolinkNode::class           => $this->renderAutolink($node),
            FootnoteRefNode::class        => $this->renderFootnoteRef($node),
            FootnotesContainerNode::class => $this->renderFootnotesContainer($node),
            FootnoteDefinitionNode::class => $this->renderFootnoteDefinition($node),
            default => throw new \RuntimeException('Unknown node type: ' . $node::class),
        };
    }

    /**
     * @param NodeInterface[] $nodes
     */
    private function renderChildren(array $nodes): string
    {
        $html = '';
        foreach ($nodes as $node) {
            $html .= $this->renderNode($node);
        }
        return $html;
    }

    /**
     * Render a run of inline nodes. When raw HTML is allowed and the run contains
     * a RawHtmlInlineNode, the whole rendered run goes through the DOM sanitizer:
     * a raw inline tag must never reach the output without it.
     *
     * @param NodeInterface[] $nodes
     */
    private function renderInline(array $nodes): string
    {
        $html = $this->renderChildren($nodes);
        if ($this->allowRawHtml && $this->containsRawHtmlInline($nodes)) {
            return $this->sanitizer->sanitize($html);
        }
        return $html;
    }

    /**
     * @param NodeInterface[] $nodes
     */
    private function containsRawHtmlInline(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if ($node instanceof RawHtmlInlineNode) {
                return true;
            }
            if (($node instanceof LinkNode
                    || $node instanceof EmphasisNode
                    || $node instanceof StrongNode
                    || $node instanceof StrikethroughNode)
                && $this->containsRawHtmlInline($node->children)
            ) {
                return true;
            }
        }
        return false;
    }

    private function renderHeading(HeadingNode $node): string
    {
        $tag = 'h' . $node->level;
        return '<' . $tag . '>' . $this->renderInline($node->children) . '</' . $tag . ">\n";
    }

    private function renderList(ListNode $node): string
    {
        $tag   = $node->ordered ? 'ol' : 'ul';
        $inner = '';
        foreach ($node->children as $item) {
            $inner .= $this->renderListItem($item, $node->loose);
        }
        $startAttr = ($node->ordered && $node->start !== null && $node->start !== 1)
            ? ' start="' . $node->start . '"'
            : '';
        return '<' . $tag . $startAttr . ">\n" . $inner . '</' . $tag . ">\n";
    }

    private function renderListItem(ListItemNode $node, bool $loose = false): string
    {
        $content = $this->renderListItemContent($node->children, $loose);

        if ($node->checked === null) {
            if ($loose) {
                return "<li>\n" . $content . "</li>\n";
            }
            return '<li>' . $content . "</li>\n";
        }
        // Same markup as cmark-gfm: XHTML-style void element, boolean attributes as ="".
        $checkbox = '<input type="checkbox"' . ($node->checked ? ' checked=""' : '') . ' disabled="" />';
        return '<li>' . $checkbox . ' ' . $content . "</li>\n";
    }

    /**
     * @param \PhpMarkdown\Node\NodeInterface[] $children
     */
    private function renderListItemContent(array $children, bool $loose): string
    {
        $inlinePart = [];
        $blockPart  = [];
        $hitBlock   = false;
        foreach ($children as $child) {
            if (!$hitBlock && $child instanceof ListNode) {
                $hitBlock = true;
            }
            if ($hitBlock) {
                $blockPart[] = $child;
            } else {
                $inlinePart[] = $child;
            }
        }

        $html = '';
        if ($inlinePart !== []) {
            $inline = $this->renderInline($inlinePart);
            $html  .= $loose ? '<p>' . $inline . "</p>\n" : $inline;
        }
        foreach ($blockPart as $block) {
            $html .= $this->renderNode($block);
        }
        return $html;
    }

    /**
     * SECURITY — mermaid output: content is htmlspecialchars-escaped (XSS-safe in HTML context).
     * Mermaid.js reads element.textContent, which the browser entity-decodes before parsing.
     * Consumers MUST initialise Mermaid with securityLevel:'strict' (never 'loose').
     * CVE-2021-43119: fixed in Mermaid >=9.1.2; use that version or newer.
     */
    private function renderFencedCode(FencedCodeNode $node): string
    {
        // Sanitize language to [A-Za-z0-9_-] as defence-in-depth before attribute output.
        $lang = preg_replace('/[^A-Za-z0-9_-]/', '', $node->language);

        if ($lang === 'mermaid') {
            return '<div class="mermaid">' . $this->esc($node->content) . "</div>\n";
        }

        $classAttr = ($lang ?? '') !== ''
            ? ' class="' . $this->esc('language-' . (string) $lang) . '"'
            : '';
        return '<pre><code' . $classAttr . '>' . $this->esc($node->content) . "</code></pre>\n";
    }

    private function renderLink(LinkNode $node): string
    {
        $titleAttr  = $node->title !== null ? ' title="' . $this->esc($node->title) . '"' : '';
        return '<a href="' . $this->esc($node->href) . '"' . $titleAttr . $this->linkAttrs . '>'
            . $this->renderChildren($node->children)
            . '</a>';
    }

    private function renderImage(ImageNode $node): string
    {
        $titleAttr = $node->title !== null
            ? ' title="' . $this->esc($node->title) . '"'
            : '';
        return '<img src="' . $this->esc($node->src) . '" alt="' . $this->esc($node->alt) . '"' . $titleAttr . ' />';
    }

    private function renderAutolink(AutolinkNode $node): string
    {
        $href = $node->isEmail
            ? 'mailto:' . $this->esc($node->url)
            : $this->esc($node->url);
        $text  = $this->esc($node->url);
        $attrs = $node->isEmail ? '' : $this->linkAttrs;
        return "<a href=\"{$href}\"{$attrs}>{$text}</a>";
    }

    /**
     * target/rel attributes from the configured linkTarget/linkRel. target="_blank"
     * always carries rel="noopener" (reverse tabnabbing), as HtmlSanitizer enforces
     * for raw HTML links.
     */
    private function buildTargetAndRelAttrs(): string
    {
        $attrs = $this->linkTarget !== null ? ' target="' . $this->esc($this->linkTarget) . '"' : '';

        $rel = $this->linkRel !== null ? (preg_split('/\s+/', trim($this->linkRel), -1, PREG_SPLIT_NO_EMPTY) ?: []) : [];
        if (strtolower($this->linkTarget ?? '') === '_blank' && !in_array('noopener', array_map('strtolower', $rel), true)) {
            $rel[] = 'noopener';
        }
        if ($rel !== []) {
            $attrs .= ' rel="' . $this->esc(implode(' ', $rel)) . '"';
        }
        return $attrs;
    }

    private function renderTable(TableNode $node): string
    {
        $header = '';
        $body   = '';
        foreach ($node->rows as $row) {
            if ($row->isHeader) {
                $header .= $this->renderTableRow($row);
            } else {
                $body .= $this->renderTableRow($row);
            }
        }
        // Block-level layout of cmark-gfm: one element per line.
        $html = "<table>\n";
        if ($header !== '') {
            $html .= "<thead>\n" . $header . "</thead>\n";
        }
        if ($body !== '') {
            $html .= "<tbody>\n" . $body . "</tbody>\n";
        }
        return $html . "</table>\n";
    }

    private function renderTableRow(TableRowNode $node): string
    {
        $html = "<tr>\n";
        foreach ($node->cells as $cell) {
            $html .= $this->renderTableCell($cell, $node->isHeader);
        }
        return $html . "</tr>\n";
    }

    private function renderTableCell(TableCellNode $node, bool $isHeader = false): string
    {
        $tag = $isHeader ? 'th' : 'td';
        // Allowlist: parseAlignments() already constrains to these values, but explicit guard prevents
        // any future refactor from accidentally passing raw user input into an HTML attribute.
        $align     = in_array($node->align, ['left', 'right', 'center'], true) ? $node->align : '';
        $alignAttr = $align !== '' ? ' align="' . $align . '"' : '';
        return '<' . $tag . $alignAttr . '>' . $this->renderInline($node->children) . '</' . $tag . ">\n";
    }

    private function renderColumns(ColumnsNode $node): string
    {
        return "<div class=\"grid grid-cols-2 gap-4\">\n"
            . "<div class=\"min-w-0\">\n" . $this->renderChildren($node->leftChildren) . "</div>\n"
            . "<div class=\"min-w-0\">\n" . $this->renderChildren($node->rightChildren) . "</div>\n"
            . "</div>\n";
    }

    private function renderFootnoteRef(FootnoteRefNode $node): string
    {
        $id = $node->occurrence === 1
            ? 'fnref-' . $node->number
            : 'fnref-' . $node->number . '-' . $node->occurrence;
        return '<sup><a href="#fn-' . $node->number . '" id="' . $id . '">'
            . $node->number . '</a></sup>';
    }

    private function renderFootnotesContainer(FootnotesContainerNode $node): string
    {
        $items = implode('', array_map(
            fn(FootnoteDefinitionNode $def) => $this->renderFootnoteDefinition($def),
            $node->definitions,
        ));
        return "<section class=\"footnotes\">\n<ol>\n" . $items . "</ol>\n</section>\n";
    }

    private function renderFootnoteDefinition(FootnoteDefinitionNode $node): string
    {
        $body = $this->renderInline($node->children);
        $backLinks = implode(' ', array_map(
            fn(string $id) => '<a href="#' . $id . '">↩</a>',
            $node->backLinkIds,
        ));
        return '<li id="fn-' . $node->number . '">' . $body . ' ' . $backLinks . "</li>\n";
    }

    private function esc(string $value): string
    {
        // Strip null bytes before encoding: \x00 is not a special HTML char so htmlspecialchars
        // passes it through, but some parsers and WAFs misinterpret payloads containing it.
        return htmlspecialchars(
            str_replace("\x00", '', $value),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
    }
}
