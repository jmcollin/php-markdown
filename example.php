<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use PhpMarkdown\MarkdownParser;

$parser = new MarkdownParser();

// ── Basic usage ───────────────────────────────────────────────────────────────

$html = $parser->parse('# Hello **World**');
echo $html . PHP_EOL;
// <h1>Hello <strong>World</strong></h1>

// ── Full feature showcase ─────────────────────────────────────────────────────

$markdown = <<<MD
# php-markdown Demo

A **lightweight** and *dependency-free* Markdown parser for PHP 8.3.

## Features

### Headings, Bold, Italic

Use `#` for headings. Use `**bold**` and *italic* inline.

### Lists

Unordered:

- First item
- Second item
- Third item

Ordered:

1. Step one
2. Step two
3. Step three

### Links and Images

[Visit the PHP documentation](https://www.php.net "PHP Docs")

![PHP Logo](https://www.php.net/images/logos/php-logo.svg)

### Code

Inline: `echo 'Hello, World!';`

Fenced block:

```php
<?php
declare(strict_types=1);

\$parser = new MarkdownParser();
echo \$parser->parse('# Hello');
```

### Blockquote

> "The best code is no code at all."

### Horizontal Rule

---

## Security

XSS attempts are neutralised automatically:

- `<script>alert(1)</script>` becomes escaped text
- `[click](javascript:alert(1))` is rendered as plain text (no `<a>` tag)
MD;

$html = $parser->parse($markdown);

echo $html . PHP_EOL;
