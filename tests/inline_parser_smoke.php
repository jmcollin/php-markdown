<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PhpMarkdown\Node\Inline\CodeNode;
use PhpMarkdown\Node\Inline\EmphasisNode;
use PhpMarkdown\Node\Inline\ImageNode;
use PhpMarkdown\Node\Inline\LinkNode;
use PhpMarkdown\Node\Inline\StrongNode;
use PhpMarkdown\Node\Inline\TextNode;
use PhpMarkdown\Parser\InlineParser;

$p = new InlineParser();
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

// AC1: Bold
$n = $p->parse('**hello**');
check(count($n) === 1 && $n[0] instanceof StrongNode, 'AC1: bold -> StrongNode');
// @phpstan-ignore-next-line property.notFound
check($n[0]->children[0] instanceof TextNode && $n[0]->children[0]->text === 'hello', 'AC1: inner TextNode "hello"');

// AC2: Italic *
$n = $p->parse('*world*');
check(count($n) === 1 && $n[0] instanceof EmphasisNode, 'AC2: *italic* -> EmphasisNode');
// @phpstan-ignore-next-line property.notFound
check($n[0]->children[0]->text === 'world', 'AC2: inner text "world"');

// AC2b: Italic _
$n = $p->parse('_world_');
check(count($n) === 1 && $n[0] instanceof EmphasisNode, 'AC2b: _italic_ -> EmphasisNode');

// AC3: Link
$n = $p->parse('[Claude](https://claude.ai)');
check(count($n) === 1 && $n[0] instanceof LinkNode, 'AC3: link -> LinkNode');
// @phpstan-ignore-next-line property.notFound
check($n[0]->href === 'https://claude.ai', 'AC3: href correct');
// @phpstan-ignore-next-line property.notFound
check($n[0]->children[0] instanceof TextNode && $n[0]->children[0]->text === 'Claude', 'AC3: link text');

// AC4: Image
$n = $p->parse('![alt text](img.png)');
check(count($n) === 1 && $n[0] instanceof ImageNode, 'AC4: image -> ImageNode');
// @phpstan-ignore-next-line property.notFound
check($n[0]->src === 'img.png' && $n[0]->alt === 'alt text', 'AC4: src/alt correct');

// AC5: Inline code
$n = $p->parse('`echo`');
check(count($n) === 1 && $n[0] instanceof CodeNode && $n[0]->code === 'echo', 'AC5: inline code');

// AC6: Mixed text
$n = $p->parse('Hello **world** and *you*');
check(count($n) === 4, 'AC6: mixed -> 4 nodes');
check($n[0] instanceof TextNode && $n[0]->text === 'Hello ', 'AC6: TextNode "Hello "');
check($n[1] instanceof StrongNode, 'AC6: StrongNode "world"');
check($n[2] instanceof TextNode && $n[2]->text === ' and ', 'AC6: TextNode " and "');
check($n[3] instanceof EmphasisNode, 'AC6: EmphasisNode "you"');

// Edge: unclosed ** → literal text
$n = $p->parse('**unclosed');
check($n[0] instanceof TextNode && $n[0]->text === '**unclosed', 'Edge: unclosed ** -> TextNode');

// Edge: double backtick
$n = $p->parse('``code with `backtick``');
check(count($n) === 1 && $n[0] instanceof CodeNode && str_contains($n[0]->code, '`'), 'Edge: double backtick encloses inner backtick');

// Edge: link with title
$n = $p->parse('[text](https://example.com "My Title")');
check($n[0] instanceof LinkNode && $n[0]->title === 'My Title', 'Edge: link with title');

// Edge: XSS in href — javascript: blocked
$n = $p->parse('[click](javascript:alert(1))');
check($n[0] instanceof TextNode, 'Security: javascript: href -> TextNode literal');

// Edge: XSS — vbscript: blocked
$n = $p->parse('[x](vbscript:msgbox(1))');
check($n[0] instanceof TextNode, 'Security: vbscript: href -> TextNode literal');

// Edge: data: URL blocked
$n = $p->parse('[x](data:text/html,<h1>xss</h1>)');
check($n[0] instanceof TextNode, 'Security: data: href -> TextNode literal');

// Edge: nested emphasis inside strong
$n = $p->parse('**bold _italic_ bold**');
check($n[0] instanceof StrongNode, 'Edge: nested -> StrongNode outer');
// @phpstan-ignore-next-line property.notFound
$inner = $n[0]->children;
check($inner[0] instanceof TextNode && $inner[0]->text === 'bold ', 'Edge: nested -> TextNode "bold "');
check($inner[1] instanceof EmphasisNode, 'Edge: nested -> EmphasisNode inner');
check($inner[2] instanceof TextNode && $inner[2]->text === ' bold', 'Edge: nested -> TextNode " bold"');

// Edge: __bold__
$n = $p->parse('__bold__');
check($n[0] instanceof StrongNode, 'Edge: __bold__ -> StrongNode');

// mailto: link allowed
$n = $p->parse('[mail](mailto:test@example.com)');
check($n[0] instanceof LinkNode, 'Security: mailto: allowed');

// Relative URL allowed (no scheme)
$n = $p->parse('[page](/about)');
check($n[0] instanceof LinkNode, 'Security: relative URL allowed');

echo PHP_EOL . "Results: {$ok} OK, {$fail} FAIL" . PHP_EOL;
// @phpstan-ignore-next-line greater.alwaysFalse
exit($fail > 0 ? 1 : 0);
