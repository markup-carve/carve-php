<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `<q>` element's children are converted to Carve before the quote wraps
 * them, so escaping the result doubled every escape the children wrote
 * (markup-carve/carve-php#2053).
 */
class AQuoteElementImportsItsContentEscapedOnceTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function htmlProvider(): array
    {
        return [
            'literal asterisks' => ['<p><q>*a*</q></p>'],
            'a backslash' => ['<p><q>a\\b</q></p>'],
            'a backslash in a code span' => ['<p><q><code>a\\b</code></q></p>'],
            'a straight quote in a code span' => ['<p><q><code>"</code></q></p>'],
            'a hard break' => ['<p><q>a<br>b</q></p>'],
            'a straight quote' => ['<p><q>a"b</q></p>'],
            'a straight quote in an emphasis' => ['<p><q><em>a"b</em></q></p>'],
            'a nested quote' => ['<p><q>a <q>b</q> c</q></p>'],
        ];
    }

    #[DataProvider('htmlProvider')]
    public function testTheImportReadsBackAsTheHtml(string $html): void
    {
        $readBack = str_replace("\n", '', CarveConverter::create()->convert((new HtmlToCarve())->convert($html)));

        $this->assertSame(str_replace(['<q>', '</q>'], ["\u{201C}", "\u{201D}"], $html), $readBack);
    }

    #[DataProvider('htmlProvider')]
    public function testTheImportIsAFixedPointOfFmt(string $html): void
    {
        $imported = (new HtmlToCarve())->convert($html);

        $this->assertSame($imported, CarveConverter::toCarve($imported));
    }

    /**
     * Control: a straight quote outside a `<q>` keeps the importer's bare form.
     */
    public function testAStraightQuoteOutsideAQuoteElementIsNotEscaped(): void
    {
        $this->assertSame("a\"b\n", (new HtmlToCarve())->convert('<p>a"b</p>'));
    }

    public function testAStraightQuoteAfterAQuoteElementIsNotEscaped(): void
    {
        $this->assertSame("\"x\"a\"b\n", (new HtmlToCarve())->convert('<p><q>x</q>a"b</p>'));
    }
}
