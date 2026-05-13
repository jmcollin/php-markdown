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
require '/path/to/src/Node/Block/HorizontalRuleNode.php';
require '/path/to/src/Node/Block/TableNode.php';
require '/path/to/src/Node/Block/TableRowNode.php';
require '/path/to/src/Node/Block/TableCellNode.php';
require '/path/to/src/Node/Inline/TextNode.php';
require '/path/to/src/Node/Inline/EmphasisNode.php';
require '/path/to/src/Node/Inline/StrongNode.php';
require '/path/to/src/Node/Inline/CodeNode.php';
require '/path/to/src/Node/Inline/LinkNode.php';
require '/path/to/src/Node/Inline/ImageNode.php';
require '/path/to/src/Lexer/TokenType.php';
require '/path/to/src/Lexer/Token.php';
require '/path/to/src/Lexer/Lexer.php';
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

---

## Supported Features

| Feature | Syntax | Output |
|---------|--------|--------|
| Headings H1–H6 | `# Heading` … `###### Heading` | `<h1>` … `<h6>` |
| Bold | `**text**` or `__text__` | `<strong>` |
| Italic | `*text*` or `_text_` | `<em>` |
| Inline code | `` `code` `` | `<code>` |
| Fenced code block | ```` ```lang … ``` ```` | `<pre><code class="language-*">` |
| Unordered list | `- item` | `<ul><li>` |
| Ordered list | `1. item` | `<ol><li>` |
| Blockquote | `> text` | `<blockquote>` |
| Horizontal rule | `---` / `***` / `___` | `<hr>` |
| Link | `[text](url)` or `[text](url "title")` | `<a href="…">` |
| Image | `![alt](src)` | `<img src="…" alt="…">` |
| GFM Table | `\| col \| col \|` + separator row | `<table><thead><tbody>` with `align` |
| Front Matter | `---\nkey: value\n---` at file top | parsed into `meta` array via `parseWithMeta()` |

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
| `InlineParser` | Recursive scanner for inline elements inside block content |
| `Parser` | Consumes `Token[]` and builds a `DocumentNode` AST |
| `HtmlRenderer` | Traverses the AST and emits escaped HTML5 |
| `FrontMatterParser` | Extracts and parses the YAML-subset front matter block |
| `MarkdownParser` | Public façade — wires all stages together |

All AST nodes are **immutable** (`readonly` properties, PHP 8.2+).

---

## Security

All user-supplied content is escaped via `htmlspecialchars(ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` before being written to output. This covers:

- Text content (all `TextNode` values)
- Code content (`CodeNode`, `FencedCodeNode`)
- HTML attributes (`href`, `src`, `alt`, `title`, `class`)

Dangerous URL schemes (`javascript:`, `vbscript:`, `data:`) in links are detected at parse time and rendered as literal text rather than `<a>` elements.

> **Note:** The library does **not** allow raw HTML pass-through. Any `<tag>` in the Markdown input is escaped and displayed as text.

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

The following CommonMark features are **not** supported in v1:

- Nested lists (list items containing sub-lists)
- Raw HTML pass-through (HTML tags in Markdown are always escaped)
- Strikethrough (`~~text~~`)
- Task lists (`- [x] item`)
- Setext-style headings (`Heading\n======`)
- Reference-style links (`[text][ref]`)
- Hard line breaks (`  \n` — two trailing spaces)
- Nested blockquotes rendered as separate nodes (consecutive `>` lines merge into one)

---

## License

MIT © 2026
