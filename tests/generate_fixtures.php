<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PhpMarkdown\MarkdownParser;

$parser = new MarkdownParser();
$base = __DIR__ . '/Integration/fixtures/';

$fixtures = [
    'headings' => "# Heading 1\n## Heading 2\n### Heading 3\n#### Heading 4\n##### Heading 5\n###### Heading 6",
    'emphasis' => "**bold text**\n\n*italic text*\n\n__also bold__\n\n_also italic_",
    'lists' => "- item one\n- item two\n- item three\n\n1. first\n2. second\n3. third",
    'code-blocks' => "```php\necho 'hello';\n```\n\nInline `code` here.",
    'links-images' => "[Visit site](https://example.com)\n\n![alt text](image.png)\n\n[With title](https://example.com \"My Title\")",
    'blockquotes' => "> This is a quote\n\n> Another quote",
    'xss-injection' => "<script>alert(1)</script>\n\n[xss](javascript:alert(1))\n\n<b>bold attempt</b>",
    'mixed' => "# Title\n\nA paragraph with **bold** and *italic*.\n\n- item 1\n- item 2\n\n> blockquote\n\n```js\nconsole.log('hi');\n```\n\n---",
];

foreach ($fixtures as $name => $markdown) {
    file_put_contents($base . $name . '.md', $markdown);
    file_put_contents($base . $name . '.html', $parser->parse($markdown));
    echo "Generated: {$name}.md + {$name}.html\n";
}
