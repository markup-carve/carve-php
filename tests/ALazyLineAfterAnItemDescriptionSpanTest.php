<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A quoted description in a list item owns its `%%%` span like one at the quote's column:
 * a line left of the body ends the span, and an unmarked line continues the item's paragraph (carve#5969).
 */
final class ALazyLineAfterAnItemDescriptionSpanTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sources(): iterable
    {
        foreach ([1, 2, 3] as $depth) {
            $q = str_repeat('> ', $depth);
            $open = str_repeat('<blockquote>', $depth);
            $close = str_repeat('</blockquote>', $depth);

            yield "note depth $depth" => [
                "$q- :: t\n$q  : [^n]: a\n$q    %%%\n$q  z\nplain\n\nx[^n]\n",
                "$open<ul><li><dl><dt>t</dt><dd></dd></dl>z\nplain</li></ul>$close",
            ];

            yield "description depth $depth" => [
                "{$q}1. :: t\n$q   : a\n$q     %%%\n$q   z\nplain\n",
                "$open<ol><li><dl><dt>t</dt><dd>a</dd></dl>z\nplain</li></ol>$close",
            ];

            yield "second description depth $depth" => [
                "$q- :: t\n$q  : x\n$q  : [^n]: a\n$q    %%%\n$q  z\nplain\n\nx[^n]\n",
                "$open<ul><li><dl><dt>t</dt><dd>x</dd><dd></dd></dl>z\nplain</li></ul>$close",
            ];
        }

        yield 'second description at the quote column' => [
            "> :: t\n> : x\n> : [^n]: a\n>   %%%\n> z\nplain\n\nx[^n]\n",
            "<blockquote><dl><dt>t</dt><dd>x</dd><dd></dd></dl><p>z\nplain</p></blockquote>",
        ];
    }

    #[DataProvider('sources')]
    public function testTheLazyLineContinuesTheParagraph(string $source, string $expected): void
    {
        $html = preg_replace('/\s*(<[^>]+>)\s*/', '$1', (new CarveConverter())->convert($source));

        self::assertStringContainsString($expected, (string)$html, $source);
    }
}
