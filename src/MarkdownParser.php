<?php

declare(strict_types=1);

namespace PhpMarkdown;

use PhpMarkdown\Exception\ParseException;
use PhpMarkdown\Lexer\Lexer;
use PhpMarkdown\Normalizer\IcuNormalizer;
use PhpMarkdown\Normalizer\NormalizerInterface;
use PhpMarkdown\Parser\FrontMatterParser;
use PhpMarkdown\Parser\Parser;
use PhpMarkdown\Renderer\HtmlRenderer;

/**
 * Public entry point for the library.
 *
 * Usage:
 *   $html = (new MarkdownParser())->parse($markdownString);
 *   ['html' => $html, 'meta' => $meta] = (new MarkdownParser())->parseWithMeta($markdownString);
 *
 * @psalm-api
 */
final class MarkdownParser
{
    /** Maximum accepted input size in bytes (1 MiB). Configurable via constructor. */
    public const DEFAULT_MAX_BYTES = 1_048_576;

    private readonly Lexer $lexer;
    private readonly Parser $parser;
    private readonly FrontMatterParser $frontMatter;
    private readonly int $maxBytes;
    private readonly NormalizerInterface $normalizer;

    public function __construct(
        int $maxBytes = self::DEFAULT_MAX_BYTES,
        ?NormalizerInterface $normalizer = null,
        private readonly ?string $linkTarget = null,
        private readonly ?string $linkRel = null,
    ) {
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException('maxBytes must be at least 1.');
        }
        $this->lexer       = new Lexer();
        $this->parser      = new Parser();
        $this->frontMatter = new FrontMatterParser();
        $this->maxBytes    = $maxBytes;
        $this->normalizer  = $normalizer ?? new IcuNormalizer();
    }

    /**
     * @throws ParseException if input exceeds maxBytes or is not valid UTF-8.
     */
    private function guard(string &$markdown): void
    {
        if (strlen($markdown) > $this->maxBytes) {
            throw new ParseException(
                sprintf('Input exceeds maximum allowed size of %d bytes.', $this->maxBytes),
            );
        }
        if (!mb_check_encoding($markdown, 'UTF-8')) {
            throw new ParseException('Input must be valid UTF-8.');
        }
        $normalized = $this->normalizer->normalize($markdown);
        $markdown = $normalized !== false ? $normalized : $markdown;
    }

    /**
     * Parse a Markdown string and return an HTML5 string.
     * Any YAML front matter block is silently stripped.
     *
     * @param bool $allowRawHtml When true, raw HTML blocks are passed through the sanitizer
     *                           instead of being escaped. Opt-in for XSS safety.
     */
    public function parse(string $markdown, bool $allowRawHtml = false): string
    {
        $this->guard($markdown);
        $extracted = $this->frontMatter->extract($markdown);
        $tokens    = $this->lexer->tokenize($extracted['markdown']);
        $ast       = $this->parser->parse($tokens);

        return $this->makeRenderer($allowRawHtml)->render($ast);
    }

    /**
     * Parse a Markdown string and return both the HTML5 output and the
     * parsed front matter metadata.
     *
     * @param bool $allowRawHtml When true, raw HTML blocks are passed through the sanitizer
     *                           instead of being escaped. Opt-in for XSS safety.
     *
     * @return array{html: string, meta: array<string, mixed>}
     */
    public function parseWithMeta(string $markdown, bool $allowRawHtml = false): array
    {
        $this->guard($markdown);
        $extracted = $this->frontMatter->extract($markdown);
        $tokens    = $this->lexer->tokenize($extracted['markdown']);
        $ast       = $this->parser->parse($tokens);

        return [
            'html' => $this->makeRenderer($allowRawHtml)->render($ast),
            'meta' => $extracted['meta'],
        ];
    }

    private function makeRenderer(bool $allowRawHtml): HtmlRenderer
    {
        return new HtmlRenderer(
            allowRawHtml: $allowRawHtml,
            linkTarget: $this->linkTarget,
            linkRel: $this->linkRel,
        );
    }
}
