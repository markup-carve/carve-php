<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `bare_opener` (CARVE-P3-013) refuses a marker after `_`, after the same
 * marker, and after `/` for `/` and `_`, so the second span takes braces
 * (markup-carve/carve-php#1998).
 */
class ASpanAfterACloserItCannotOpenAgainstIsBracedTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function bracedProvider(): array
    {
        return [
            'italic after italic' => ['{/x/}{/y/}', "/x/{/y/}\n"],
            'bold after bold' => ['{*x*}{*y*}', "*x*{*y*}\n"],
            'underline after underline' => ['{_x_}{_y_}', "_x_{_y_}\n"],
            'strike after strike' => ['{~x~}{~y~}', "~x~{~y~}\n"],
            'highlight after highlight' => ['{=x=}{=y=}', "=x={=y=}\n"],
            'bold after underline' => ['{_x_}{*y*}', "_x_{*y*}\n"],
            'underline after italic' => ['{/x/}{_y_}', "/x/{_y_}\n"],
            'three in a row' => ['{/x/}{/y/}{/z/}', "/x/{/y/}/z/\n"],
            'a bare closer outside the pair' => ['~{/x/}{/y~/}', "~/x/{/y~/}\n"],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function bareProvider(): array
    {
        return [
            'italic after bold' => ['{*x*}{/y/}', "*x*/y/\n"],
            'bold after italic' => ['{/x/}{*y*}', "/x/*y*\n"],
        ];
    }

    #[DataProvider('bracedProvider')]
    public function testBracesTheSecondSpan(string $source, string $formatted): void
    {
        $this->assertSame($formatted, $this->format($source));
        $this->assertSame($this->html($source), $this->html($formatted));
    }

    #[DataProvider('bareProvider')]
    public function testKeepsTheSecondSpanBareWhereItMayOpen(string $source, string $formatted): void
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
