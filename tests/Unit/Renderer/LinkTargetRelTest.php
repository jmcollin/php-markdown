<?php

declare(strict_types=1);

namespace PhpMarkdown\Tests\Unit\Renderer;

use PhpMarkdown\MarkdownParser;
use PHPUnit\Framework\TestCase;

/**
 * linkTarget / linkRel options: target="_blank" always carries rel="noopener".
 */
final class LinkTargetRelTest extends TestCase
{
    public function testBlankTargetWithoutRelGetsNoopener(): void
    {
        $html = (new MarkdownParser(linkTarget: '_blank'))->parse('[a](https://x.example) <https://y.example>');

        $this->assertSame(
            '<p><a href="https://x.example" target="_blank" rel="noopener">a</a> '
            . '<a href="https://y.example" target="_blank" rel="noopener">https://y.example</a></p>' . "\n",
            $html,
        );
    }

    public function testNoopenerIsAppendedToConfiguredRel(): void
    {
        $html = (new MarkdownParser(linkTarget: '_blank', linkRel: 'nofollow'))->parse('[a](/x)');

        $this->assertStringContainsString('rel="nofollow noopener"', $html);
    }

    public function testNoopenerIsNotDuplicated(): void
    {
        $html = (new MarkdownParser(linkTarget: '_BLANK', linkRel: 'NoOpener nofollow'))->parse('[a](/x)');

        $this->assertStringContainsString('rel="NoOpener nofollow"', $html);
    }

    public function testOtherTargetsAndEmailAutolinksAreUnchanged(): void
    {
        $this->assertStringContainsString(
            '<a href="/x" target="_self">',
            (new MarkdownParser(linkTarget: '_self'))->parse('[a](/x)'),
        );
        $this->assertStringContainsString(
            '<a href="mailto:a@b.example">',
            (new MarkdownParser(linkTarget: '_blank'))->parse('<a@b.example>'),
        );
    }
}
