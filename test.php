<?php

/**
 * Standalone test runner — no PHPUnit required.
 * Usage: php test.php
 * Exit code: 0 = all pass, 1 = failures
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use PhpMarkdown\MarkdownParser;

$ok = 0;
$fail = 0;

function expect(string $expected, string $actual, string $label): void
{
    global $ok, $fail;
    if ($expected === $actual) {
        echo "[OK]   {$label}\n";
        $ok++;
    } else {
        echo "[FAIL] {$label}\n";
        echo "       Expected: " . addcslashes($expected, "\n") . "\n";
        echo "       Got:      " . addcslashes($actual, "\n") . "\n";
        $fail++;
    }
}

function contains(string $needle, string $haystack, string $label): void
{
    global $ok, $fail;
    if (str_contains($haystack, $needle)) {
        echo "[OK]   {$label}\n";
        $ok++;
    } else {
        echo "[FAIL] {$label}\n";
        echo "       Expected to contain: {$needle}\n";
        $fail++;
    }
}

function notContains(string $needle, string $haystack, string $label): void
{
    global $ok, $fail;
    if (!str_contains($haystack, $needle)) {
        echo "[OK]   {$label}\n";
        $ok++;
    } else {
        echo "[FAIL] {$label}\n";
        echo "       Expected NOT to contain: {$needle}\n";
        $fail++;
    }
}

$parser = new MarkdownParser();

echo "=== Headings ===" . PHP_EOL;
expect('<h1>Hello</h1>',       $parser->parse('# Hello'),         'H1');
expect('<h2>World</h2>',       $parser->parse('## World'),        'H2');
expect('<h6>Six</h6>',         $parser->parse('###### Six'),      'H6');

echo PHP_EOL . "=== Paragraphs ===" . PHP_EOL;
expect('<p>Simple text</p>',   $parser->parse('Simple text'),     'Paragraph');
expect('',                     $parser->parse(''),                'Empty input');

echo PHP_EOL . "=== Emphasis ===" . PHP_EOL;
expect('<p><strong>bold</strong></p>', $parser->parse('**bold**'), 'Bold **');
expect('<p><strong>bold</strong></p>', $parser->parse('__bold__'), 'Bold __');
expect('<p><em>italic</em></p>',       $parser->parse('*italic*'), 'Italic *');
expect('<p><em>italic</em></p>',       $parser->parse('_italic_'), 'Italic _');

echo PHP_EOL . "=== Lists ===" . PHP_EOL;
expect('<ul><li>a</li><li>b</li></ul>', $parser->parse("- a\n- b"), 'Unordered list');
expect('<ol><li>a</li><li>b</li></ol>', $parser->parse("1. a\n2. b"), 'Ordered list');

echo PHP_EOL . "=== Code ===" . PHP_EOL;
contains('<code>php_info()</code>', $parser->parse('`php_info()`'), 'Inline code');
contains('language-php',            $parser->parse("```php\ncode\n```"), 'Fenced code language');
contains('&amp;',                   $parser->parse("```\na & b\n```"), 'Fenced code content escaped');

echo PHP_EOL . "=== Links & Images ===" . PHP_EOL;
expect('<p><a href="https://example.com">site</a></p>', $parser->parse('[site](https://example.com)'), 'Link');
expect('<p><img src="logo.png" alt="logo"></p>',        $parser->parse('![logo](logo.png)'),            'Image');

echo PHP_EOL . "=== Block elements ===" . PHP_EOL;
expect('<blockquote><p>quote</p></blockquote>', $parser->parse('> quote'), 'Blockquote');
expect('<hr>',                                  $parser->parse('---'),     'Horizontal rule ---');
expect('<hr>',                                  $parser->parse('***'),     'Horizontal rule ***');

echo PHP_EOL . "=== Security (XSS) ===" . PHP_EOL;
notContains('<script>',  $parser->parse('<script>alert(1)</script>'),     'Script tag blocked');
contains('&lt;script&gt;', $parser->parse('<script>alert(1)</script>'),  'Script tag escaped');
notContains('<a ',       $parser->parse('[x](javascript:alert(1))'),      'javascript: no <a>');
notContains('<a ',       $parser->parse('[x](vbscript:foo)'),             'vbscript: no <a>');
notContains('<a ',       $parser->parse('[x](data:text/html,xss)'),       'data: no <a>');
contains('&lt;b&gt;',   $parser->parse('<b>attempt</b>'),                'HTML tags escaped');

echo PHP_EOL . "=== Mixed document ===" . PHP_EOL;
$mixed = <<<MD
# My Doc

Hello **world** and *PHP*.

- item 1
- item 2

> A quote

```php
echo 'done';
```

---
MD;
$html = $parser->parse($mixed);
contains('<h1>My Doc</h1>',          $html, 'Mixed: h1');
contains('<strong>world</strong>',   $html, 'Mixed: strong');
contains('<em>PHP</em>',             $html, 'Mixed: em');
contains('<ul>',                     $html, 'Mixed: ul');
contains('<blockquote>',             $html, 'Mixed: blockquote');
contains('language-php',             $html, 'Mixed: code block');
contains('<hr>',                     $html, 'Mixed: hr');

echo PHP_EOL;
$total = $ok + $fail;
echo "Results: {$ok}/{$total} passed";
if ($fail > 0) {
    echo ", {$fail} FAILED";
}
echo PHP_EOL;

exit($fail > 0 ? 1 : 0);
