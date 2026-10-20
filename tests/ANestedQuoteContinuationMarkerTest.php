<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ANestedQuoteContinuationMarkerTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function sourceProvider(): array
    {
        $nested = '<blockquote><blockquote><p>q</p><p>para</p></blockquote></blockquote>';
        $closed = '<blockquote><blockquote><p>q</p></blockquote></blockquote>';

        return [
            'prefixed attached paragraph' => ["> > q\n> +\n> para\n", $nested],
            'unprefixed attached paragraph' => ["> > q\n> +\npara\n", $nested],
            'marker at end of input' => ["> > q\n> +\n", $closed],
            'blank line ends the quote' => ["> > q\n> +\n\npara\n", $closed . '<p>para</p>'],
            'nested quote in a list item' => ["- > > q\n  > +\n\npara\n", '<ul><li><blockquote><blockquote><p>q +</p></blockquote></blockquote></li></ul><p>para</p>'],
            'plus at inner content column is text' => ["> > q\n> > +\n\npara\n", '<blockquote><blockquote><p>q +</p></blockquote></blockquote><p>para</p>'],
            'unnested unprefixed marker' => ["> q\n+\npara\n", '<blockquote><p>q</p><p>para</p></blockquote>'],
            'leading plus is paragraph text' => ["> +\n\npara\n", '<blockquote><p>+</p></blockquote><p>para</p>'],
            'plus in a quoted list item' => ["> - a\n> +\n> para\n", '<blockquote><ul><li>a para</li></ul></blockquote>'],
        ];
    }

    #[DataProvider('sourceProvider')]
    public function testNestedQuoteContinuationMarker(string $source, string $expected): void
    {
        $html = CarveConverter::create()->convert($source);
        $html = (string)preg_replace('/\s+/', ' ', $html);
        $html = str_replace('> <', '><', $html);
        $html = (string)preg_replace('/\s+(?=<\/)/', '', $html);

        self::assertSame($expected, trim($html));
    }
}
