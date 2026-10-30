<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `+ b |` continuation row in a quote nested in another quote is more table
 * (markup-carve/carve#1349), so it leaves no paragraph open and a flush-left
 * line after it ends the list item (PART 1 S4).
 */
class AContinuationRowInANestedQuoteEndsTheItemsParagraphTest extends TestCase
{
    /**
     * @return array<string, array{string, int}>
     */
    public static function cases(): array
    {
        return [
            'two quotes' => ["- > > | a |\n  > > + b |\ntail\n", 2],
            'three quotes' => ["- > > > | a |\n  > > > + b |\ntail\n", 3],
        ];
    }

    #[DataProvider('cases')]
    public function testTheFlushLeftLineIsAParagraphAfterTheList(string $source, int $depth): void
    {
        $table = "<table>\n<tbody>\n<tr><td>a b</td></tr>\n</tbody>\n</table>";
        $expected = "<ul>\n<li>\n" . str_repeat("<blockquote>\n", $depth) . $table . "\n"
            . str_repeat("</blockquote>\n", $depth) . "</li>\n</ul>\n<p>tail</p>";
        $html = trim(CarveConverter::create()->convert($source));

        $this->assertSame($expected, (string)preg_replace('/^ +/m', '', $html));
    }
}
