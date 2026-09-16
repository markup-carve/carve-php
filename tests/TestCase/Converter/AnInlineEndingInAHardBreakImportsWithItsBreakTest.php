<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The importer trims an inline's content, which took the newline of a trailing
 * hard break and left its backslash escaping the closer
 * (markup-carve/carve-php#2047).
 */
class AnInlineEndingInAHardBreakImportsWithItsBreakTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function htmlProvider(): array
    {
        return [
            'strike after text' => ['<p><s>x<br></s></p>'],
            'strike alone' => ['<p><s><br></s></p>'],
            'strike between letters' => ['<p>a<s><br></s>b</p>'],
            'emphasis' => ['<p><em>x<br></em></p>'],
            'strong between words' => ['<p>a <strong>x<br></strong> b</p>'],
            'highlight' => ['<p><mark>x<br></mark></p>'],
            'underline' => ['<p><u>x<br></u></p>'],
            'superscript' => ['<p><sup>x<br></sup></p>'],
            'subscript between letters' => ['<p>a<sub><br></sub>b</p>'],
            'insertion' => ['<p><ins>x<br></ins></p>'],
            'deletion' => ['<p>(<del><br></del>)</p>'],
            'two breaks' => ['<p><s>x<br>y<br></s></p>'],
            'whitespace after the break' => ['<p><s>x<br> </s></p>'],
            'link label' => ['<p><a href="u">x<br></a></p>'],
            'text backslash before the break' => ['<p><s>x\\<br></s></p>'],
        ];
    }

    #[DataProvider('htmlProvider')]
    public function testTheImportReadsBackAsTheHtml(string $html): void
    {
        $readBack = str_replace("\n", '', CarveConverter::create()->convert((new HtmlToCarve())->convert($html)));

        $this->assertSame(str_replace('<br> ', '<br>', $html), $readBack);
    }

    #[DataProvider('htmlProvider')]
    public function testTheImportIsAFixedPointOfFmt(string $html): void
    {
        $imported = (new HtmlToCarve())->convert($html);

        $this->assertSame($imported, CarveConverter::toCarve($imported));
    }

    /**
     * Control: an even backslash run is text, and gains no newline.
     */
    public function testATrailingTextBackslashStaysText(): void
    {
        $html = '<p><s>x\\</s></p>';

        $this->assertSame($html, trim(CarveConverter::create()->convert((new HtmlToCarve())->convert($html))));
    }
}
