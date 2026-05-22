<?php

declare(strict_types=1);

namespace PhpMarkdown\Renderer;

use PhpMarkdown\Node\Block\BlockquoteNode;
use PhpMarkdown\Node\Block\ColumnsNode;
use PhpMarkdown\Node\Block\DocumentNode;
use PhpMarkdown\Node\Block\FencedCodeNode;
use PhpMarkdown\Node\Block\HeadingNode;
use PhpMarkdown\Node\Block\HorizontalRuleNode;
use PhpMarkdown\Node\Block\ListItemNode;
use PhpMarkdown\Node\Block\ListNode;
use PhpMarkdown\Node\Block\ParagraphNode;
use PhpMarkdown\Node\Block\RawHtmlBlockNode;
use PhpMarkdown\Node\Block\TableCellNode;
use PhpMarkdown\Node\Block\TableNode;
use PhpMarkdown\Node\Block\TableRowNode;

use PhpMarkdown\Node\Inline\CodeNode;
use PhpMarkdown\Node\Inline\EmphasisNode;
use PhpMarkdown\Node\Inline\HardBreakNode;
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
 */
final class HtmlRenderer
{
    public function __construct(
        private readonly bool $allowRawHtml = false,
        private readonly HtmlSanitizer $sanitizer = new HtmlSanitizer(),
    ) {
    }

    public function render(DocumentNode $document): string
    {
        return $this->renderChildren($document->children);
    }

    private function renderNode(NodeInterface $node): string
    {
        return match (true) {
            $node instanceof HeadingNode      => $this->renderHeading($node),
            $node instanceof ParagraphNode    => '<p>' . $this->renderChildren($node->children) . '</p>',
            $node instanceof BlockquoteNode   => '<blockquote>' . $this->renderChildren($node->children) . '</blockquote>',
            $node instanceof ListNode         => $this->renderList($node),
            $node instanceof ListItemNode     => $this->renderListItem($node),
            $node instanceof FencedCodeNode   => $this->renderFencedCode($node),
            $node instanceof HorizontalRuleNode => '<hr>',
            $node instanceof ColumnsNode       => $this->renderColumns($node),
            // Raw HTML blocks: sanitize if allowRawHtml, else escape (XSS-safe default).
            $node instanceof RawHtmlBlockNode =>
                $this->allowRawHtml
                    ? $this->sanitizer->sanitize($node->content)
                    : $this->esc($node->content),
            $node instanceof HardBreakNode    => '<br>',
            // HTML entities pass through verbatim — validated by InlineParser, no esc() needed.
            $node instanceof HtmlEntityNode   => $node->entity,
            $node instanceof TextNode         => $this->esc($node->text),
            $node instanceof StrongNode       => '<strong>' . $this->renderChildren($node->children) . '</strong>',
            $node instanceof StrikethroughNode => '<del>' . $this->renderChildren($node->children) . '</del>',
            $node instanceof EmphasisNode     => '<em>' . $this->renderChildren($node->children) . '</em>',
            $node instanceof CodeNode         => '<code>' . $this->esc($node->code) . '</code>',
            $node instanceof LinkNode         => $this->renderLink($node),
            $node instanceof ImageNode        => $this->renderImage($node),
            $node instanceof TableNode        => $this->renderTable($node),
            $node instanceof TableRowNode     => $this->renderTableRow($node),
            // Inline HTML: regex-strip dangerous attrs if allowRawHtml (DOMDocument auto-closes fragments),
            // else escape. Strips on*, style, and javascript:/data: URL attrs.
            $node instanceof RawHtmlInlineNode =>
                $this->allowRawHtml
                    ? $this->stripInlineAttrs($node->content)
                    : $this->esc($node->content),
            default => throw new \RuntimeException('Unknown node type: ' . $node::class),
        };
    }

    /**
     * @param NodeInterface[] $nodes
     */
    private function renderChildren(array $nodes): string
    {
        return implode('', array_map($this->renderNode(...), $nodes));
    }

    private function renderHeading(HeadingNode $node): string
    {
        $tag = 'h' . $node->level;
        return '<' . $tag . '>' . $this->renderChildren($node->children) . '</' . $tag . '>';
    }

    private function renderList(ListNode $node): string
    {
        $tag = $node->ordered ? 'ol' : 'ul';
        return '<' . $tag . '>' . $this->renderChildren($node->children) . '</' . $tag . '>';
    }

    private function renderListItem(ListItemNode $node): string
    {
        if ($node->checked === null) {
            return '<li>' . $this->renderChildren($node->children) . '</li>';
        }
        $checkbox = '<input type="checkbox" disabled' . ($node->checked ? ' checked' : '') . '>';
        return '<li>' . $checkbox . ' ' . $this->renderChildren($node->children) . '</li>';
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
            return '<div class="mermaid">' . $this->esc($node->content) . '</div>';
        }

        $classAttr = ($lang ?? '') !== ''
            ? ' class="' . $this->esc('language-' . (string) $lang) . '"'
            : '';
        return '<pre><code' . $classAttr . '>' . $this->esc($node->content) . '</code></pre>';
    }

    private function renderLink(LinkNode $node): string
    {
        $titleAttr = $node->title !== null
            ? ' title="' . $this->esc($node->title) . '"'
            : '';
        return '<a href="' . $this->esc($node->href) . '"' . $titleAttr . '>'
            . $this->renderChildren($node->children)
            . '</a>';
    }

    private function renderImage(ImageNode $node): string
    {
        $titleAttr = $node->title !== null
            ? ' title="' . $this->esc($node->title) . '"'
            : '';
        return '<img src="' . $this->esc($node->src) . '" alt="' . $this->esc($node->alt) . '"' . $titleAttr . '>';
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
        $html = '<table>';
        if ($header !== '') {
            $html .= '<thead>' . $header . '</thead>';
        }
        if ($body !== '') {
            $html .= '<tbody>' . $body . '</tbody>';
        }
        return $html . '</table>';
    }

    private function renderTableRow(TableRowNode $node): string
    {
        $cells = implode('', array_map(
            fn(TableCellNode $cell) => $this->renderTableCell($cell, $node->isHeader),
            $node->cells,
        ));
        return '<tr>' . $cells . '</tr>';
    }

    private function renderTableCell(TableCellNode $node, bool $isHeader = false): string
    {
        $tag = $isHeader ? 'th' : 'td';
        // Allowlist: parseAlignments() already constrains to these values, but explicit guard prevents
        // any future refactor from accidentally passing raw user input into an HTML attribute.
        $align     = in_array($node->align, ['left', 'right', 'center'], true) ? $node->align : '';
        $alignAttr = $align !== '' ? ' align="' . $align . '"' : '';
        return '<' . $tag . $alignAttr . '>' . $this->renderChildren($node->children) . '</' . $tag . '>';
    }

    private function renderColumns(ColumnsNode $node): string
    {
        return '<div class="grid grid-cols-2 gap-4">'
            . '<div class="min-w-0">' . $this->renderChildren($node->leftChildren) . '</div>'
            . '<div class="min-w-0">' . $this->renderChildren($node->rightChildren) . '</div>'
            . '</div>';
    }

    private function stripInlineAttrs(string $tag): string
    {
        // Strip all on* event handler attributes (onclick, onerror, onload, etc.).
        $tag = preg_replace('/\s+on\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>\/]*)/i', '', $tag) ?? $tag;
        // Strip style attribute (CSS expression / url(javascript:...) vectors).
        $tag = preg_replace('/\s+style\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>\/]*)/i', '', $tag) ?? $tag;
        // Strip href/src/action with javascript: or data: scheme.
        $tag = preg_replace(
            '/\s+(?:href|src|action|formaction)\s*=\s*(?:"(?:javascript|data)[^"]*"|\'(?:javascript|data)[^\']*\'|(?:javascript|data)[^\s>\/]*)/i',
            '',
            $tag,
        ) ?? $tag;
        return $tag;
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
