<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit;

use PhpMarkdown\MarkdownParser;
use PhpMarkdown\Normalizer\IcuNormalizer;
use PhpMarkdown\Normalizer\NormalizerInterface;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

final class NormalizerSeamTest extends TestCase
{
    public function testFalseNormalizerFallsBackToOriginalInput(): void
    {
        $falseNormalizer = new class implements NormalizerInterface {
            /** Normalize the input string; returns false if normalization failed — caller must fall back to original input. */
            public function normalize(string $input): string|false
            {
                return false;
            }
        };

        $parser = new MarkdownParser(normalizer: $falseNormalizer);
        $output = $parser->parse('hello');

        $this->assertStringContainsString('hello', $output);
    }

    #[RequiresPhpExtension('intl')]
    public function testIcuNormalizerConvertsNfdToNfc(): void
    {
        $normalizer = new IcuNormalizer();

        // NFD: 'e' + combining acute accent (U+0301) — two codepoints
        $nfd = "e\u{0301}";
        // NFC: precomposed é (U+00E9) — one codepoint
        $nfc = "\u{00E9}";

        $result = $normalizer->normalize($nfd);

        $this->assertSame($nfc, $result);
    }
}
