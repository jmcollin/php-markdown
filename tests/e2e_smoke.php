<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PhpMarkdown\MarkdownParser;

$parser = new MarkdownParser();
$ok = 0;
$fail = 0;

function check(bool $cond, string $label): void
{
    global $ok, $fail;
    if ($cond) {
        echo '[OK]   ' . $label . PHP_EOL;
        $ok++;
    } else {
        echo '[FAIL] ' . $label . PHP_EOL;
        $fail++;
    }
}

// Heading
$html = $parser->parse('# Hello');
check($html === '<h1>Hello</h1>', 'E2E: h1');

// Paragraph
$html = $parser->parse('Simple text');
check($html === '<p>Simple text</p>', 'E2E: paragraph');

// Bold + italic
$html = $parser->parse('**bold** and *italic*');
check($html === '<p><strong>bold</strong> and <em>italic</em></p>', 'E2E: bold+italic');

// Unordered list
$html = $parser->parse("- one\n- two");
check($html === '<ul><li>one</li><li>two</li></ul>', 'E2E: unordered list');

// Ordered list
$html = $parser->parse("1. first\n2. second");
check($html === '<ol><li>first</li><li>second</li></ol>', 'E2E: ordered list');

// Blockquote
$html = $parser->parse('> quote text');
check($html === '<blockquote><p>quote text</p></blockquote>', 'E2E: blockquote');

// Fenced code
$html = $parser->parse("```php\necho 'hi';\n```");
check(str_contains($html, 'class="language-php"') && str_contains($html, 'echo'), 'E2E: fenced code php');

// Link
$html = $parser->parse('[Claude](https://claude.ai)');
check($html === '<p><a href="https://claude.ai">Claude</a></p>', 'E2E: link');

// Image
$html = $parser->parse('![logo](logo.png)');
check($html === '<p><img src="logo.png" alt="logo"></p>', 'E2E: image');

// Inline code
$html = $parser->parse('Use `php_info()` here');
check(str_contains($html, '<code>php_info()</code>'), 'E2E: inline code');

// Horizontal rule
$html = $parser->parse('---');
check($html === '<hr>', 'E2E: horizontal rule');

// XSS: script tag in content
$html = $parser->parse('<script>alert(1)</script>');
check(!str_contains($html, '<script>'), 'E2E Security: script tag escaped');
check(str_contains($html, '&lt;script&gt;'), 'E2E Security: script -> &lt;script&gt;');

// XSS: javascript: link blocked at parser level — no href, rendered as text
$html = $parser->parse('[x](javascript:alert(1))');
check(!str_contains($html, 'href='), 'E2E Security: javascript: link has no href');
check(!str_contains($html, '<a '), 'E2E Security: javascript: link not rendered as <a>');

// Mixed document
$md = <<<MD
# My Document

Hello **world**.

- item 1
- item 2

> A wise quote

```php
echo 'done';
```
MD;
$html = $parser->parse($md);
check(str_contains($html, '<h1>My Document</h1>'), 'E2E mixed: h1');
check(str_contains($html, '<strong>world</strong>'), 'E2E mixed: bold');
check(str_contains($html, '<ul>'), 'E2E mixed: list');
check(str_contains($html, '<blockquote>'), 'E2E mixed: blockquote');
check(str_contains($html, '<pre><code'), 'E2E mixed: code block');

echo PHP_EOL . "Results: {$ok} OK, {$fail} FAIL" . PHP_EOL;
// @phpstan-ignore-next-line greater.alwaysFalse
exit($fail > 0 ? 1 : 0);
