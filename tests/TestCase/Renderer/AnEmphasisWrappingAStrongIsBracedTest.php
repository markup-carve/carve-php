<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `/*` opens `bold_italic` and `*` followed by `/` closes it, so a bare
 * emphasis whose content has both reads back as the other nesting
 * (markup-carve/carve-php#2012).
 */
class AnEmphasisWrappingAStrongIsBracedTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function bracedProvider(): array
    {
        return [
            'a strong alone in an emphasis' => ['{/*x*/}', "{/*x*/}\n"],
            'the bare-outer spelling' => ['/{*x*}/', "{/*x*/}\n"],
            'the braced-inner spelling' => ['{/{*x*}/}', "{/*x*/}\n"],
            'a strong at each edge' => ['{/*x* y *z*/}', "{/*x* y *z*/}\n"],
            'two braced children at the edges' => ['{/{*x*} y {*z*}/}', "{/*x* y *z*/}\n"],
            'in running text' => ['a {/*x*/} b', "a {/*x*/} b\n"],
            'a sibling strong after it' => ['{/*x*/}{*y*}', "{/*x*/}*y*\n"],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function bareProvider(): array
    {
        return [
            'the combined form itself' => ['/*x*/', "/*x*/\n"],
            'an emphasis inside a strong' => ['{*/x/*}', "*/x/*\n"],
            'a strong at the leading edge only' => ['{/*x* y/}', "/*x* y/\n"],
            'a strong at the trailing edge only' => ['{/y *x*/}', "/y *x*/\n"],
            'an underline wrapping a strong' => ['{_*x*_}', "_*x*_\n"],
            'a strike wrapping a strong' => ['{~*x*~}', "~*x*~\n"],
        ];
    }

    #[DataProvider('bracedProvider')]
    public function testBracesTheEmphasis(string $source, string $formatted): void
    {
        $this->assertSame($formatted, $this->format($source));
        $this->assertSame($this->html($source), $this->html($formatted));
    }

    #[DataProvider('bareProvider')]
    public function testKeepsTheEmphasisBareWhereNoCombinedTokenForms(string $source, string $formatted): void
    {
        $this->assertSame($formatted, $this->format($source));
        $this->assertSame($this->html($source), $this->html($formatted));
    }

    protected function format(string $source): string
    {
        return (new CarveRenderer())->render((new CarveConverter())->parse($source));
    }

    protected function html(string $source): string
    {
        return (new HtmlRenderer())->render((new CarveConverter())->parse($source));
    }
}
