# php-markdown

A lightweight, standalone PHP library (8.2–8.4) that parses CommonMark-flavored Markdown and converts it into clean, semantic HTML5. **Zero runtime dependencies.**

![PHP](https://img.shields.io/badge/PHP-8.2%20to%208.4-blue)
![PSR-12](https://img.shields.io/badge/style-PSR--12-brightgreen)
![No dependencies](https://img.shields.io/badge/dependencies-none-brightgreen)
![License](https://img.shields.io/badge/license-MIT-blue)

---

## Requirements

- PHP **8.2** to **8.4**
- No external packages required for core usage

---

## Installation

### Via Composer (recommended)

```bash
composer require php-markdown/parser
```

### Manual (no Composer)

Copy the `src/` directory into your project, then require the files in this order:

```php
require '/path/to/src/Exception/ParseException.php';
require '/path/to/src/Node/NodeInterface.php';
require '/path/to/src/Node/BlockNodeInterface.php';
require '/path/to/src/Node/InlineNodeInterface.php';
require '/path/to/src/Node/Block/DocumentNode.php';
require '/path/to/src/Node/Block/HeadingNode.php';
require '/path/to/src/Node/Block/ParagraphNode.php';
require '/path/to/src/Node/Block/BlockquoteNode.php';
require '/path/to/src/Node/Block/ListNode.php';
require '/path/to/src/Node/Block/ListItemNode.php';
require '/path/to/src/Node/Block/FencedCodeNode.php';
require '/path/to/src/Node/Block/IndentedCodeNode.php';
require '/path/to/src/Node/Block/HorizontalRuleNode.php';
require '/path/to/src/Node/Block/TableNode.php';
require '/path/to/src/Node/Block/TableRowNode.php';
require '/path/to/src/Node/Block/TableCellNode.php';
require '/path/to/src/Node/Block/RawHtmlBlockNode.php';
require '/path/to/src/Node/Block/ColumnsNode.php';
require '/path/to/src/Node/Block/FootnoteDefinitionNode.php';
require '/path/to/src/Node/Block/FootnotesContainerNode.php';
require '/path/to/src/Node/Inline/TextNode.php';
require '/path/to/src/Node/Inline/EmphasisNode.php';
require '/path/to/src/Node/Inline/StrongNode.php';
require '/path/to/src/Node/Inline/StrikethroughNode.php';
require '/path/to/src/Node/Inline/CodeNode.php';
require '/path/to/src/Node/Inline/LinkNode.php';
require '/path/to/src/Node/Inline/ImageNode.php';
require '/path/to/src/Node/Inline/AutolinkNode.php';
require '/path/to/src/Node/Inline/HardBreakNode.php';
require '/path/to/src/Node/Inline/HtmlEntityNode.php';
require '/path/to/src/Node/Inline/RawHtmlInlineNode.php';
require '/path/to/src/Node/Inline/FootnoteRefNode.php';
require '/path/to/src/Normalizer/NormalizerInterface.php';
require '/path/to/src/Normalizer/IcuNormalizer.php';
require '/path/to/src/Sanitizer/HtmlSanitizer.php';
require '/path/to/src/Lexer/TokenType.php';
require '/path/to/src/Lexer/Token.php';
require '/path/to/src/Lexer/Lexer.php';
require '/path/to/src/Parser/DelimiterRun.php';
require '/path/to/src/Parser/FlankingComputer.php';
require '/path/to/src/Parser/DelimiterStack.php';
require '/path/to/src/Parser/InlineParser.php';
require '/path/to/src/Parser/Parser.php';
require '/path/to/src/Parser/FrontMatterParser.php';
require '/path/to/src/Renderer/HtmlRenderer.php';
require '/path/to/src/MarkdownParser.php';
```

---

## Try it — Docker Playground

No PHP installation required. From the project root:

```bash
docker-compose up --build
```

Open `http://localhost:8080` in your browser. See [`playground/README.md`](playground/README.md)
for full setup details, port-override instructions, and caveats.

---

## Quick Start

```php
<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';

use PhpMarkdown\MarkdownParser;

$parser = new MarkdownParser();
$html   = $parser->parse('# Hello **World**');

echo $html; // <h1>Hello <strong>World</strong></h1>
```

### Front Matter

```php
$markdown = <<<MD
---
title: My Post
published: true
tags: php
---

# My Post

Content here.
MD;

['html' => $html, 'meta' => $meta] = $parser->parseWithMeta($markdown);

echo $meta['title'];     // My Post
echo $meta['published']; // true (bool)
echo $html;              // <h1>My Post</h1><p>Content here.</p>
```

`parse()` also accepts front matter — it strips the block silently, so existing code needs no changes.

### GFM Tables

```php
$markdown = <<<MD
| Name  | Score |
|:------|------:|
| Alice |    95 |
| Bob   |    87 |
MD;

echo $parser->parse($markdown);
// <table><thead><tr><th align="left">Name</th><th align="right">Score</th></tr></thead>
// <tbody><tr><td align="left">Alice</td><td align="right">95</td></tr>...
```

### Raw HTML pass-through

Raw HTML is escaped by default (XSS-safe). Opt in to sanitized pass-through with `allowRawHtml: true`:

```php
$html = $parser->parse('<div class="note">text</div>', allowRawHtml: true);
// <div class="note">text</div>  — dangerous tags/attrs stripped by HtmlSanitizer
```

---

## Supported Features

### Block elements

| Feature | Syntax | Output |
|---------|--------|--------|
| Headings H1–H6 (ATX) | `# Heading` … `###### Heading` | `<h1>` … `<h6>` |
| Headings H1–H2 (Setext) | `Heading\n===` / `Heading\n---` | `<h1>` / `<h2>` |
| Paragraph | plain text | `<p>` |
| Blockquote (nested) | `> text` | `<blockquote>` |
| Unordered list | `- item` / `* item` / `+ item` | `<ul><li>` |
| Ordered list | `1. item` | `<ol><li>` |
| Nested lists | indented `- item` inside list item | `<ul>` inside `<li>` |
| Task list | `- [x] done` / `- [ ] todo` | `<li><input type="checkbox" …>` |
| Fenced code block | ```` ```lang … ``` ```` | `<pre><code class="language-*">` |
| Mermaid diagram | ```` ```mermaid … ``` ```` | `<div class="mermaid">` |
| Indented code block | 4-space / 1-tab indent | `<pre><code>` |
| Horizontal rule | `---` / `***` / `___` | `<hr>` |
| GFM Table | `\| col \| col \|` + separator row | `<table><thead><tbody>` with `align` |
| Front Matter | `---\nkey: value\n---` at file top | parsed into `meta` array via `parseWithMeta()` |
| Raw HTML block | `<div>…</div>` | escaped (default) or sanitized (`allowRawHtml: true`) |
| Two-column layout | `:::columns … \|\|\| … :::` | `<div class="grid grid-cols-2 …">` |
| Footnote definition | `[^label]: body` | rendered in `<section class="footnotes">` |

### Inline elements

| Feature | Syntax | Output |
|---------|--------|--------|
| Bold | `**text**` or `__text__` | `<strong>` |
| Italic | `*text*` or `_text_` | `<em>` |
| Strikethrough | `~~text~~` | `<del>` |
| Inline code | `` `code` `` | `<code>` |
| Link (inline) | `[text](url)` or `[text](url "title")` | `<a href="…">` |
| Link (reference) | `[text][ref]` with `[ref]: url` definition | `<a href="…">` |
| Image | `![alt](src)` or `![alt](src "title")` | `<img src="…" alt="…">` |
| Autolink | `<https://…>` or `<user@example.com>` | `<a href="…">` |
| Hard line break | two trailing spaces + newline | `<br>` |
| HTML entity | `&amp;`, `&#42;`, etc. | passed through verbatim |
| Raw HTML inline | `<span class="x">` | escaped (default) or sanitized (`allowRawHtml: true`) |
| Footnote reference | `[^label]` | `<sup><a href="#fn-…">` |

---

## Architecture

The library is built as a three-stage pipeline. Each stage is independent and testable in isolation.

```
Input string
    │
    ▼
┌─────────┐   Token[]    ┌────────┐   DocumentNode   ┌──────────┐
│  Lexer  │ ──────────►  │ Parser │ ───────────────►  │ Renderer │ ──► HTML string
└─────────┘              └────────┘                   └──────────┘
  One-pass                Builds AST                   Stateless
  O(n)                    Block + Inline                traversal
```

| Class | Responsibility |
|-------|---------------|
| `Lexer` | Tokenises the input line by line into `Token[]` |
| `InlineParser` | Recursive delimiter-stack scanner for inline elements |
| `Parser` | Consumes `Token[]` and builds a `DocumentNode` AST |
| `HtmlRenderer` | Traverses the AST and emits escaped HTML5 |
| `HtmlSanitizer` | DOM-based sanitizer used when `allowRawHtml: true` |
| `FrontMatterParser` | Extracts and parses the YAML-subset front matter block |
| `IcuNormalizer` | NFC-normalizes input via PHP `intl` if available |
| `MarkdownParser` | Public façade — wires all stages together |

All AST nodes are **immutable** (`readonly` properties, PHP 8.2+).

---

## Security

All user-supplied content is escaped via `htmlspecialchars(ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` before being written to output. This covers:

- Text content (all `TextNode` values)
- Code content (`CodeNode`, `FencedCodeNode`)
- HTML attributes (`href`, `src`, `alt`, `title`, `class`)

Dangerous URL schemes (`javascript:`, `vbscript:`, `data:`) in links are detected at parse time and rendered as literal text rather than `<a>` elements.

**Raw HTML** is escaped by default. Passing `allowRawHtml: true` enables DOM-based sanitization via `HtmlSanitizer`:
- Forbidden tags (`script`, `iframe`, `form`, etc.) are removed with their entire subtree.
- Unknown tags are unwrapped (children promoted).
- All `on*` event handlers, `style`, and `javascript:`/`data:` URL attributes are stripped.
- `target="_blank"` links get `rel="noopener"` injected automatically.

---

## Testing

```bash
# PHPUnit (requires dev dependencies)
composer install
php vendor/bin/phpunit --no-coverage

# Standalone (no PHPUnit needed)
php test.php
```

---

## Limitations

The following are **not** supported:

- LaTeX / math (`$…$`, `$$…$$`)
- Definition lists
- Custom HTML attributes in Markdown syntax

---

## License

MIT © 2026
