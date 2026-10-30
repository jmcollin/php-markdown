<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use League\CommonMark\CommonMarkConverter;
use Michelf\Markdown;
use PhpMarkdown\MarkdownParser;

// === Document generators ===
function createSampleMarkdown(string $size): string
{
    $section = <<<'MD'
## Section: Features

This paragraph contains **bold text**, *italic text*, and `inline code`.
It also has a [link to example](https://example.com "Example") and some plain text.

### Lists

Unordered:

- First item with some content
- Second item with **bold** content
- Third item with a [link](https://example.com)

Ordered:

1. Step one: initialize the parser
2. Step two: run the benchmark
3. Step three: analyze results

### Code block

```php
<?php
declare(strict_types=1);

$parser = new MarkdownParser();
$html = $parser->parse('# Hello World');
echo $html;
```

### Blockquote

> Performance matters. A fast parser reduces latency in real applications.
> This benchmark measures real-world throughput across multiple parsers.

### Table

| Parser               | Speed     | Memory |
|----------------------|-----------|--------|
| php-markdown/parser  | fast      | low    |
| Parsedown            | very fast | low    |
| michelf/php-markdown | medium    | low    |
| league/commonmark    | slow      | high   |

---

MD;

    $counts = ['small' => 1, 'medium' => 10, 'large' => 80];
    $repeat = $counts[$size] ?? 1;

    $header = "# Benchmark Document ({$size})\n\n";

    return $header . str_repeat($section, $repeat);
}

// === Benchmark function ===
function benchmark(string $name, callable $parser, string $markdown, int $iterations): array
{
    // warmup
    $parser($markdown);

    $start       = microtime(true);
    $memoryStart = memory_get_usage();

    for ($i = 0; $i < $iterations; $i++) {
        $parser($markdown);
    }

    $time   = (microtime(true) - $start) * 1000;
    $memory = (memory_get_usage() - $memoryStart) / 1024 / 1024;

    return [
        'parser'      => $name,
        'time_ms'     => round($time, 2),
        'time_per_op' => round($time / $iterations, 4),
        'memory_mb'   => round($memory, 2),
        'ops_per_sec' => round($iterations / ($time / 1000), 0),
    ];
}

// === Parsers ===
$parsers = [
    'php-markdown/parser' => function (string $md) {
        $parser = new MarkdownParser();
        return $parser->parse($md);
    },
    'league/commonmark' => function (string $md) {
        $converter = new CommonMarkConverter();
        return $converter->convert($md);
    },
    'Parsedown' => function (string $md) {
        $parsedown = new Parsedown();
        return $parsedown->text($md);
    },
    'michelf/php-markdown' => function (string $md) {
        return Markdown::defaultTransform($md);
    },
];

// === Sizes config ===
$sizes = [
    'small'  => 1000,
    'medium' => 200,
    'large'  => 20,
];

// === Run ===
foreach ($sizes as $size => $iterations) {
    $markdown = createSampleMarkdown($size);
    $chars    = strlen($markdown);

    echo "\n" . str_repeat("=", 80) . "\n";
    echo strtoupper($size) . " ({$chars} chars) — {$iterations} iterations\n";
    echo str_repeat("=", 80) . "\n";

    $results = [];
    foreach ($parsers as $name => $parser) {
        echo "  Testing {$name}... ";
        $results[] = benchmark($name, $parser, $markdown, $iterations);
        echo "✓\n";
    }

    usort($results, fn($a, $b) => $a['time_per_op'] <=> $b['time_per_op']);

    echo "\n";
    foreach ($results as $r) {
        echo sprintf(
            "  %-25s | %8.4f ms/op | %8d ops/s | %6.2f MB\n",
            $r['parser'],
            $r['time_per_op'],
            $r['ops_per_sec'],
            $r['memory_mb']
        );
    }
}

echo "\n" . str_repeat("=", 80) . "\n";
