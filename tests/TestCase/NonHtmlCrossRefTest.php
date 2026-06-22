<?php

declare(strict_types=1);

namespace Carve\Test\TestCase;

use Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NonHtmlCrossRefTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function simpleHeadingRefProvider(): array
    {
        return [
            'markdown' => ['markdown', '[Title](#Title)', 'See .'],
            'plainText' => ['plainText', 'See Title.', 'See .'],
            'ansi' => ['ansi', 'See Title.', 'See .'],
        ];
    }

    #[DataProvider('simpleHeadingRefProvider')]
    public function testHeadingRefResolves(string $format, string $expected, string $emptyOutput): void
    {
        $output = $this->convert($format, "# Title\n\nSee </#title>.");

        $this->assertStringContainsString($expected, $output);
        $this->assertStringNotContainsString($emptyOutput, $output);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function forwardHeadingRefProvider(): array
    {
        return [
            'markdown' => ['markdown', '[Title](#Title)', 'See .'],
            'plainText' => ['plainText', 'See Title.', 'See .'],
            'ansi' => ['ansi', 'See Title.', 'See .'],
        ];
    }

    #[DataProvider('forwardHeadingRefProvider')]
    public function testForwardHeadingRefResolves(string $format, string $expected, string $emptyOutput): void
    {
        $output = $this->convert($format, "See </#title>.\n\n# Title");

        $this->assertStringContainsString($expected, $output);
        $this->assertStringNotContainsString($emptyOutput, $output);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function numberedCaptionRefProvider(): array
    {
        return [
            'markdown' => ['markdown', 'See Figure 1.', 'See .'],
            'plainText' => ['plainText', 'See Figure 1.', 'See .'],
            'ansi' => ['ansi', 'See Figure 1.', 'See .'],
        ];
    }

    #[DataProvider('numberedCaptionRefProvider')]
    public function testNumberedCaptionRefResolves(string $format, string $expected, string $emptyOutput): void
    {
        $djot = <<<'DJOT'
{#fig}
![A sunset](sun.jpg)
^ Figure #: A sunset

See </#fig>.
DJOT;
        $output = $this->convert($format, $djot);

        $this->assertStringContainsString('Figure 1', $output);
        $this->assertStringContainsString($expected, $output);
        $this->assertStringNotContainsString($emptyOutput, $output);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unresolvedHeadingRefProvider(): array
    {
        return [
            'markdown' => ['markdown', "See </#nope>.\n"],
            'plainText' => ['plainText', "See </#nope>.\n"],
            'ansi' => ['ansi', "See </#nope>.\n"],
        ];
    }

    #[DataProvider('unresolvedHeadingRefProvider')]
    public function testUnresolvedHeadingRefRendersLiteralSource(string $format, string $expected): void
    {
        $this->assertSame($expected, $this->convert($format, 'See </#nope>.'));
    }

    public function testReferenceLinksAndCrossReferencesResolveInsideFootnoteDefinitions(): void
    {
        $html = (new CarveConverter())->convert(<<<'DJOT'
# H

Use[^n].

[^n]: [x][r] and </#h>.

[r]: https://e.example
DJOT);

        $this->assertStringContainsString('<a href="https://e.example">x</a>', $html);
        $this->assertStringContainsString('<a href="#H">H</a>', $html);
        $this->assertStringNotContainsString('[x][r]', $html);
        $this->assertStringNotContainsString('&lt;/#h&gt;', $html);
    }

    protected function convert(string $format, string $djot): string
    {
        $converter = match ($format) {
            'markdown' => CarveConverter::markdown(),
            'plainText' => CarveConverter::plainText(),
            'ansi' => CarveConverter::ansi(),
        };

        if ($format === 'ansi') {
            /** @var \Carve\Renderer\AnsiRenderer $renderer */
            $renderer = $converter->getRenderer();
            $renderer->setUseColors(false);
        }

        return $converter->convert($djot);
    }
}
