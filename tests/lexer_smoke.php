<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PhpMarkdown\Lexer\Lexer;
use PhpMarkdown\Lexer\TokenType;

$lexer = new Lexer();
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

// AC1: Heading
$t = $lexer->tokenize('## Hello World');
check(count($t) === 1 && $t[0]->type === TokenType::HEADING && $t[0]->meta['level'] === 2 && $t[0]->content === 'Hello World', 'Heading ## detected');

// AC2: Fenced code block
$t = $lexer->tokenize("```php\necho 'x';\n```");
check(count($t) === 1 && $t[0]->type === TokenType::FENCED_CODE && $t[0]->meta['language'] === 'php' && $t[0]->content === "echo 'x';", 'Fenced code php');

// AC3: Unordered list
$t = $lexer->tokenize("- item1\n- item2");
check(count($t) === 2 && $t[0]->type === TokenType::LIST_ITEM && $t[0]->meta['ordered'] === false && $t[0]->content === 'item1', 'UL item1');
check($t[1]->content === 'item2', 'UL item2');

// AC4: Blockquote
$t = $lexer->tokenize('> citation');
check(count($t) === 1 && $t[0]->type === TokenType::BLOCKQUOTE && $t[0]->content === 'citation', 'Blockquote');

// AC5: Paragraph fallback
$t = $lexer->tokenize('Simple text');
check(count($t) === 1 && $t[0]->type === TokenType::PARAGRAPH && $t[0]->content === 'Simple text', 'Paragraph fallback');

// Edge: heading without space after #
$t = $lexer->tokenize('##nospace');
check($t[0]->type === TokenType::PARAGRAPH, 'Heading no space -> PARAGRAPH');

// Edge: unclosed fenced block
$t = $lexer->tokenize("```\norphan line");
check(count($t) === 1 && $t[0]->type === TokenType::FENCED_CODE && $t[0]->content === 'orphan line', 'Unclosed fenced block -> emitted');

// Edge: empty input
$t = $lexer->tokenize('');
check(count($t) === 0, 'Empty input returns []');

// Edge: blank line
$t = $lexer->tokenize("text\n\ntext2");
check($t[1]->type === TokenType::BLANK, 'Blank line -> BLANK token');

// Edge: ordered list
$t = $lexer->tokenize('1. First');
check($t[0]->type === TokenType::LIST_ITEM && $t[0]->meta['ordered'] === true && $t[0]->content === 'First', 'Ordered list detected');

// Edge: nested blockquote
$t = $lexer->tokenize('>> deep');
check($t[0]->type === TokenType::BLOCKQUOTE && $t[0]->meta['level'] === 2, 'Nested blockquote level=2');

// Edge: horizontal rule variants
$t = $lexer->tokenize('---');
check($t[0]->type === TokenType::HORIZONTAL_RULE, 'HR ---');
$t = $lexer->tokenize('***');
check($t[0]->type === TokenType::HORIZONTAL_RULE, 'HR ***');
$t = $lexer->tokenize('___');
check($t[0]->type === TokenType::HORIZONTAL_RULE, 'HR ___');

// All heading levels
foreach (range(1, 6) as $level) {
    $t = $lexer->tokenize(str_repeat('#', $level) . ' Title');
    check($t[0]->type === TokenType::HEADING && $t[0]->meta['level'] === $level, "Heading H{$level}");
}

// Perf: 1000 lines < 10ms
$chunk = ['# Heading', 'paragraph text', '- list item', '> blockquote', '1. ordered', '---', '', 'more text', 'bold text', 'end'];
$lines = implode("\n", array_merge(...array_fill(0, 100, $chunk)));
$start = microtime(true);
for ($i = 0; $i < 5; $i++) {
    $lexer->tokenize($lines);
}
$avg = (microtime(true) - $start) / 5 * 1000;
check($avg < 10, 'Perf: 1000 lines avg=' . round($avg, 2) . 'ms < 10ms');

echo PHP_EOL . "Results: {$ok} OK, {$fail} FAIL" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
