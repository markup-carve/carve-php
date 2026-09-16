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

        $this->assertSame($this->withQuoteMarks($html), $readBack);
    }

    /**
     * The HTML with each `<q>` replaced by the pair a browser draws for it:
     * double outside, single one level in (markup-carve/carve-php#2096).
     */
    protected function withQuoteMarks(string $html): string
    {
        $depth = 0;

        return (string)preg_replace_callback(
            '#</?q>#',
            function (array $match) use (&$depth): string {
                if ($match[0] === '</q>') {
                    $depth--;

                    return $depth % 2 === 0 ? "\u{201D}" : "\u{2019}";
                }

                $mark = $depth % 2 === 0 ? "\u{201C}" : "\u{2018}";
                $depth++;

                return $mark;
            },
            $html,
        );
    }

    #[DataProvider('htmlProvider')]
    public function testTheImportIsAFixedPointOfFmt(string $html): void
    {
        $imported = (new HtmlToCarve())->convert($html);

        $this->assertSame($imported, CarveConverter::toCarve($imported));
    }

    /**
     * A cite goes through the shared attribute helper, whose unquoted form is
     * the one the writer keeps (markup-carve/carve-php#2096).
     */
    public function testACitedQuoteIsAFixedPointOfFmt(): void
    {
        $imported = (new HtmlToCarve())->convert('<p><q cite="https://e.com">a</q></p>');

        $this->assertSame("[\u{201C}a\u{201D}]{cite=https://e.com}\n", $imported);
        $this->assertSame($imported, CarveConverter::toCarve($imported));
    }

    /**
     * Control: a straight quote after a `<q>` is escaped once, like any other.
     */
    public function testAStraightQuoteAfterAQuoteElementIsEscapedOnce(): void
    {
        $this->assertSame(
            "\u{201C}x\u{201D}a\\\"b\n",
            (new HtmlToCarve())->convert('<p><q>x</q>a"b</p>'),
        );
    }
}
