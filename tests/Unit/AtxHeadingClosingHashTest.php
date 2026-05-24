<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit;

use PhpMarkdown\Lexer\Lexer;
use PhpMarkdown\Lexer\TokenType;
use PhpMarkdown\Parser\Parser;
use PhpMarkdown\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AtxHeadingClosingHashTest extends TestCase
{
    private Lexer $lexer;
    private Parser $parser;
    private HtmlRenderer $renderer;

    protected function setUp(): void
    {
        $this->lexer    = new Lexer();
        $this->parser   = new Parser();
        $this->renderer = new HtmlRenderer();
    }

    private function tokenize(string $input): array
    {
        return $this->lexer->tokenize($input);
    }

    private function render(string $input): string
    {
        return trim($this->renderer->render($this->parser->parse($this->tokenize($input))));
    }

    // --- Lexer-level token content assertions ---

    #[DataProvider('closingHashStrippedProvider')]
    public function testClosingHashesStripped(string $input, int $level, string $expectedContent): void
    {
        $tokens = $this->tokenize($input);

        $this->assertCount(1, $tokens);
        $this->assertSame(TokenType::HEADING, $tokens[0]->type);
        $this->assertSame($level, $tokens[0]->meta['level']);
        $this->assertSame($expectedContent, $tokens[0]->content);
    }

    /** @return array<string, array{string, int, string}> */
    public static function closingHashStrippedProvider(): array
    {
        return [
            'symmetric closing hashes'            => ['## Foo ##',     2, 'Foo'],
            'asymmetric closing hashes'           => ['# Foo ###',     1, 'Foo'],
            'single closing hash'                 => ['## Foo #',      2, 'Foo'],
            'h3 entirely hashes'                  => ['### ###',       3, ''],
            'h2 entirely hashes'                  => ['## ##',         2, ''],
            'trailing spaces after closing hash'  => ["## Foo ##   ",  2, 'Foo'],
            'multiple spaces before closing hash' => ['# Bar   ##',    1, 'Bar'],
            'no closing hashes unchanged'         => ['## Foo',        2, 'Foo'],
        ];
    }

    public function testClosingHashesNotStrippedWithoutPrecedingSpace(): void
    {
        $tokens = $this->tokenize('## Foo##');

        $this->assertSame(TokenType::HEADING, $tokens[0]->type);
        $this->assertSame('Foo##', $tokens[0]->content);
    }

    public function testMidContentHashNotStripped(): void
    {
        $tokens = $this->tokenize('## Foo # Bar');

        $this->assertSame('Foo # Bar', $tokens[0]->content);
    }

    public function testEscapedHashAtEndNotStripped(): void
    {
        $tokens = $this->tokenize('## Foo \#');

        $this->assertSame('Foo \#', $tokens[0]->content);
    }

    // --- Renderer-level end-to-end assertions ---

    #[DataProvider('renderProvider')]
    public function testRendersCorrectHtml(string $input, string $expectedHtml): void
    {
        $this->assertSame($expectedHtml, $this->render($input));
    }

    /** @return array<string, array{string, string}> */
    public static function renderProvider(): array
    {
        return [
            'symmetric closing hashes'   => ['## Foo ##',  '<h2>Foo</h2>'],
            'asymmetric closing hashes'  => ['# Foo ###',  '<h1>Foo</h1>'],
            'entirely hashes h2'         => ['## ##',      '<h2></h2>'],
            'no closing hashes'          => ['## Foo',     '<h2>Foo</h2>'],
            'no-space not stripped'      => ['## Foo##',   '<h2>Foo##</h2>'],
            'mid-content hash preserved' => ['## Foo # Bar', '<h2>Foo # Bar</h2>'],
        ];
    }
}
