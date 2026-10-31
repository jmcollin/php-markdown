<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Security;

use PhpMarkdown\MarkdownParser;
use PHPUnit\Framework\TestCase;

/**
 * A long indented code block must lex in linear time: the lexer used to copy
 * the whole block for every new line, so a 40k-line block took ~20 s.
 */
final class IndentedCodeDosTest extends TestCase
{
    public function testLongIndentedCodeBlockParsesQuickly(): void
    {
        // Blank lines between code lines exercise the pending-blank path too.
        $n     = 40_000;
        $start = microtime(true);
        $html  = (new MarkdownParser())->parse(str_repeat("    x\n\n", $n));
        $this->assertLessThan(5.0, microtime(true) - $start);

        // One block; interior blank lines are kept, trailing ones are not.
        $this->assertSame(1, substr_count($html, '<pre><code>'));
        $this->assertSame($n, substr_count($html, 'x'));
        $this->assertStringEndsWith("x\n</code></pre>\n", $html);
        $this->assertStringContainsString("x\n\nx\n", $html);
    }
}
