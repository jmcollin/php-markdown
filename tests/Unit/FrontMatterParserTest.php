<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit;

use PhpMarkdown\Parser\FrontMatterParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FrontMatterParserTest extends TestCase
{
    private FrontMatterParser $parser;

    protected function setUp(): void
    {
        $this->parser = new FrontMatterParser();
    }

    public function testNoFrontMatterReturnsFull(): void
    {
        $result = $this->parser->extract("# Hello\n\nWorld");
        $this->assertSame("# Hello\n\nWorld", $result['markdown']);
        $this->assertSame([], $result['meta']);
    }

    public function testBasicFrontMatter(): void
    {
        $input = "---\ntitle: My Post\ndate: 2024-01-15\n---\n\n# Body";
        $result = $this->parser->extract($input);
        $this->assertSame('My Post', $result['meta']['title']);
        $this->assertSame('2024-01-15', $result['meta']['date']);
        $this->assertStringContainsString('# Body', $result['markdown']);
    }

    public function testMarkdownBodyStrippedOfFrontMatter(): void
    {
        $input = "---\ntitle: X\n---\n\nParagraph here.";
        $result = $this->parser->extract($input);
        $this->assertStringNotContainsString('title:', $result['markdown']);
        $this->assertStringNotContainsString('---', $result['markdown']);
    }

    public function testBooleanCasting(): void
    {
        // Only true/false are treated as booleans; yes/no remain strings (avoid country-code collisions).
        $input = "---\npublished: true\ndraft: false\nfeatured: yes\nhidden: no\n---\n";
        $result = $this->parser->extract($input);
        $this->assertTrue($result['meta']['published']);
        $this->assertFalse($result['meta']['draft']);
        $this->assertSame('yes', $result['meta']['featured']);
        $this->assertSame('no', $result['meta']['hidden']);
    }

    public function testIntegerCasting(): void
    {
        $result = $this->parser->extract("---\ncount: 42\nnegative: -7\n---\n");
        $this->assertSame(42, $result['meta']['count']);
        $this->assertSame(-7, $result['meta']['negative']);
    }

    public function testLeadingZeroNotCastToInt(): void
    {
        $result = $this->parser->extract("---\ncode: 0123\nzero: 0\n---\n");
        $this->assertSame('0123', $result['meta']['code']); // no octal-style coercion
        $this->assertSame(0, $result['meta']['zero']);       // bare zero is fine
    }

    public function testDuplicateKeyKeepsFirst(): void
    {
        $result = $this->parser->extract("---\ntitle: First\ntitle: Second\n---\n");
        $this->assertSame('First', $result['meta']['title']);
    }

    public function testInvalidKeyIgnored(): void
    {
        $result = $this->parser->extract("---\n__proto__: evil\nvalid_key: ok\n---\n");
        $this->assertArrayNotHasKey('__proto__', $result['meta']);
        $this->assertSame('ok', $result['meta']['valid_key']);
    }

    public function testFloatCasting(): void
    {
        $result = $this->parser->extract("---\nprice: 9.99\n---\n");
        $this->assertSame(9.99, $result['meta']['price']);
    }

    public function testNullValues(): void
    {
        $result = $this->parser->extract("---\nfoo: null\nbar: ~\nbaz:\n---\n");
        $this->assertNull($result['meta']['foo']);
        $this->assertNull($result['meta']['bar']);
        $this->assertNull($result['meta']['baz']);
    }

    public function testQuotedStrings(): void
    {
        $result = $this->parser->extract("---\na: \"true\"\nb: '42'\n---\n");
        $this->assertSame('true', $result['meta']['a']);
        $this->assertSame('42', $result['meta']['b']);
    }

    public function testCommentLinesIgnored(): void
    {
        $result = $this->parser->extract("---\n# this is a comment\ntitle: Real\n---\n");
        $this->assertArrayNotHasKey('# this is a comment', $result['meta']);
        $this->assertSame('Real', $result['meta']['title']);
    }

    public function testUnclosedFrontMatterTreatedAsBody(): void
    {
        $input = "---\ntitle: X\n\nNo closing delimiter here.";
        $result = $this->parser->extract($input);
        $this->assertSame([], $result['meta']);
        $this->assertSame($input, $result['markdown']);
    }

    public function testNoDelimiterAtStartNotParsed(): void
    {
        $input = "Some text\n---\ntitle: X\n---\n";
        $result = $this->parser->extract($input);
        $this->assertSame([], $result['meta']);
    }

    public function testParseWithMetaIntegration(): void
    {
        $mp = new \PhpMarkdown\MarkdownParser();
        $input = "---\ntitle: Hello\nauthor: Alice\n---\n\n# Hello\n\nParagraph.";
        $result = $mp->parseWithMeta($input);

        $this->assertArrayHasKey('html', $result);
        $this->assertArrayHasKey('meta', $result);
        $this->assertSame('Hello', $result['meta']['title']);
        $this->assertSame('Alice', $result['meta']['author']);
        $this->assertStringContainsString('<h1>', $result['html']);
        $this->assertStringNotContainsString('title:', $result['html']);
    }

    public function testParseBackwardCompat(): void
    {
        $mp = new \PhpMarkdown\MarkdownParser();
        $input = "---\ntitle: X\n---\n\n# Body";
        $html = $mp->parse($input);

        $this->assertStringContainsString('<h1>', $html);
        $this->assertStringNotContainsString('title:', $html);
        $this->assertStringNotContainsString('---', $html);
    }

    public function testSingleQuoteCharNotStripped(): void
    {
        // A bare single/double quote as value must not be mangled by the quote-strip logic
        $result = $this->parser->extract("---\na: \"\"\nb: ''\n---\n");
        $this->assertSame('', $result['meta']['a']);
        $this->assertSame('', $result['meta']['b']);
    }

    public function testInlineSequenceStrings(): void
    {
        $result = $this->parser->extract("---\ntags: [javascript, performance, react]\n---\n");
        $this->assertSame(['javascript', 'performance', 'react'], $result['meta']['tags']);
    }

    public function testInlineSequenceEmpty(): void
    {
        $result = $this->parser->extract("---\ntags: []\n---\n");
        $this->assertSame([], $result['meta']['tags']);
    }

    public function testInlineSequenceMixedTypes(): void
    {
        $result = $this->parser->extract("---\nvalues: [42, true, null]\n---\n");
        $this->assertSame([42, true, null], $result['meta']['values']);
    }

    public function testInlineSequenceSingleElement(): void
    {
        $result = $this->parser->extract("---\ntags: [foo]\n---\n");
        $this->assertSame(['foo'], $result['meta']['tags']);
    }

    public function testQuotedStringWithBracketsNotParsedAsSequence(): void
    {
        $result = $this->parser->extract("---\ntitle: \"[see ref]\"\n---\n");
        $this->assertSame('[see ref]', $result['meta']['title']);
    }

    public function testInputSizeCapThrows(): void
    {
        $mp = new \PhpMarkdown\MarkdownParser(maxBytes: 10);
        $this->expectException(\PhpMarkdown\Exception\ParseException::class);
        $mp->parse(str_repeat('a', 11));
    }

    public function testInvalidUtf8Throws(): void
    {
        $mp = new \PhpMarkdown\MarkdownParser();
        $this->expectException(\PhpMarkdown\Exception\ParseException::class);
        $mp->parse("\xFF\xFE invalid utf8");
    }
}
