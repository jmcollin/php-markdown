<?php

declare(strict_types=1);

namespace PhpMarkdown\Normalizer;

final class IcuNormalizer implements NormalizerInterface
{
    /** Normalize the input string; returns false if normalization failed — caller must fall back to original input. */
    public function normalize(string $input): string|false
    {
        return \Normalizer::normalize($input, \Normalizer::NFC);
    }
}
