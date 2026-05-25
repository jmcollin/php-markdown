<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PhpMarkdown\Node\Block\BlockquoteNode;
use PhpMarkdown\Node\Block\DocumentNode;
use PhpMarkdown\Node\Block\FencedCodeNode;
use PhpMarkdown\Node\Block\HeadingNode;
use PhpMarkdown\Node\Block\HorizontalRuleNode;
use PhpMarkdown\Node\Block\ListItemNode;
use PhpMarkdown\Node\Block\ListNode;
use PhpMarkdown\Node\Block\ParagraphNode;
use PhpMarkdown\Node\Inline\CodeNode;
use PhpMarkdown\Node\Inline\EmphasisNode;
use PhpMarkdown\Node\Inline\ImageNode;
use PhpMarkdown\Node\Inline\LinkNode;
use PhpMarkdown\Node\Inline\StrongNode;
use PhpMarkdown\Node\Inline\TextNode;
use PhpMarkdown\Renderer\HtmlRenderer;

$r = new HtmlRenderer();
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

/** @param \PhpMarkdown\Node\NodeInterface[] $children */
function doc(array $children): DocumentNode
{
    return new DocumentNode($children);
}

// AC1: Heading
$out = $r->render(doc([new HeadingNode(2, [new TextNode('Hello')])]));
check($out === '<h2>Hello</h2>', 'AC1: h2 rendered');

foreach (range(1, 6) as $l) {
    $out = $r->render(doc([new HeadingNode($l, [new TextNode('T')])]));
    check($out === "<h{$l}>T</h{$l}>", "AC1: h{$l} rendered");
}

// AC2: XSS in TextNode
$out = $r->render(doc([new ParagraphNode([new TextNode('<script>alert(1)</script>')])]));
check(
    $out === '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>',
    'AC2: XSS in TextNode escaped'
);

// AC3: Link
$out = $r->render(doc([new ParagraphNode([
    new LinkNode('https://example.com', [new TextNode('click')]),
])]));
check($out === '<p><a href="https://example.com">click</a></p>', 'AC3: link rendered');

// AC3b: Link with title
$out = $r->render(doc([new ParagraphNode([
    new LinkNode('https://example.com', [new TextNode('x')], 'My Title'),
])]));
check(str_contains($out, 'title="My Title"'), 'AC3b: link with title attr');

// AC4: Image
$out = $r->render(doc([new ParagraphNode([
    new ImageNode('img.png', 'description'),
])]));
check($out === '<p><img src="img.png" alt="description"></p>', 'AC4: image rendered');

// AC5: Fenced code with language
$out = $r->render(doc([new FencedCodeNode("echo 'x';", 'php')]));
check(
    $out === '<pre><code class="language-php">echo &#039;x&#039;;</code></pre>',
    'AC5: fenced code php, content escaped'
);

// AC5b: Fenced code without language
$out = $r->render(doc([new FencedCodeNode('plain code')]));
check($out === '<pre><code>plain code</code></pre>', 'AC5b: fenced code no language');

// AC6: Unordered list
$out = $r->render(doc([new ListNode(ordered: false, children: [
    new ListItemNode([new TextNode('one')]),
    new ListItemNode([new TextNode('two')]),
])]));
check($out === '<ul><li>one</li><li>two</li></ul>', 'AC6: unordered list');

// AC6b: Ordered list
$out = $r->render(doc([new ListNode(ordered: true, children: [
    new ListItemNode([new TextNode('a')]),
])]));
check($out === '<ol><li>a</li></ol>', 'AC6b: ordered list');

// AC7: Blockquote
$out = $r->render(doc([new BlockquoteNode([
    new ParagraphNode([new TextNode('quote')]),
])]));
check($out === '<blockquote><p>quote</p></blockquote>', 'AC7: blockquote rendered');

// Edge: empty DocumentNode
$out = $r->render(doc([]));
check($out === '', 'Edge: empty DocumentNode -> empty string');

// Edge: horizontal rule
$out = $r->render(doc([new HorizontalRuleNode()]));
check($out === '<hr>', 'Edge: HorizontalRuleNode -> <hr>');

// Edge: inline code escaped
$out = $r->render(doc([new ParagraphNode([new CodeNode('<b>')])]));
check($out === '<p><code>&lt;b&gt;</code></p>', 'Edge: inline code content escaped');

// Edge: & in text escaped
$out = $r->render(doc([new ParagraphNode([new TextNode('a & b')])]));
check($out === '<p>a &amp; b</p>', 'Edge: & escaped in TextNode');

// Edge: quote in attr
$out = $r->render(doc([new ParagraphNode([
    new ImageNode('img.png', '"quoted"'),
])]));
check(str_contains($out, '&quot;quoted&quot;'), 'Edge: quotes escaped in alt attr');

// Edge: XSS in href (reaches renderer, already safe scheme — esc the value)
$out = $r->render(doc([new ParagraphNode([
    new LinkNode('https://ex.com?q=<b>', [new TextNode('x')]),
])]));
check(str_contains($out, '&lt;b&gt;'), 'Edge: XSS in href attr escaped');

// Edge: strong and em rendered
$out = $r->render(doc([new ParagraphNode([
    new StrongNode([new TextNode('bold')]),
    new EmphasisNode([new TextNode('italic')]),
])]));
check($out === '<p><strong>bold</strong><em>italic</em></p>', 'Edge: strong+em rendered');

// Perf: 500 nodes < 5ms
$items = array_fill(0, 50, new ListItemNode([new TextNode('item')]));
$blocks = array_merge(
    [new HeadingNode(1, [new TextNode('Title')])],
    array_fill(0, 9, new ListNode(ordered: false, children: $items)),
);
$bigDoc = new DocumentNode($blocks);
$start = microtime(true);
for ($i = 0; $i < 10; $i++) {
    $r->render($bigDoc);
}
$avg = (microtime(true) - $start) / 10 * 1000;
check($avg < 5, 'Perf: ~500 nodes avg=' . round($avg, 2) . 'ms < 5ms');

echo PHP_EOL . "Results: {$ok} OK, {$fail} FAIL" . PHP_EOL;
// @phpstan-ignore-next-line greater.alwaysFalse
exit($fail > 0 ? 1 : 0);
