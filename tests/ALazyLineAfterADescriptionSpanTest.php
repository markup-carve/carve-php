<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `%%%` in a description body or its note is that body's span: a text line left of the
 * body ends it (PART 0 S1), and an unmarked line continues that paragraph (S4, carve#5886).
 */
final class ALazyLineAfterADescriptionSpanTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sources(): iterable
    {
        yield 'note body span' => [
            ":: t\n: [^n]: a\n    %%%\n  z\nplain\n\nx[^n]\n",
            "<dl><dt>t</dt><dd>z\nplain</dd></dl>",
        ];

        yield 'quoted note body span' => [
            "> :: t\n> : [^n]: a\n>     %%%\n>   z\nplain\n\nx[^n]\n",
            "<blockquote><dl><dt>t</dt><dd>z\nplain</dd></dl></blockquote>",
        ];

        foreach ([1, 2, 3] as $depth) {
            $q = str_repeat('> ', $depth);
            $open = str_repeat('<blockquote>', $depth);
            $close = str_repeat('</blockquote>', $depth);

            yield "note depth $depth" => [
                "$q:: t\n$q: [^n]: a\n$q  %%%\n{$q}z\nplain\n\nx[^n]\n",
                "$open<dl><dt>t</dt><dd></dd></dl><p>z\nplain</p>$close",
            ];

            yield "description depth $depth" => [
                "$q:: t\n$q: a\n$q  %%%\n{$q}z\nplain\n",
                "$open<dl><dt>t</dt><dd>a</dd></dl><p>z\nplain</p>$close",
            ];
        }
    }

    #[DataProvider('sources')]
    public function testTheLazyLineContinuesTheParagraph(string $source, string $expected): void
    {
        $html = preg_replace('/>\s+</', '><', (new CarveConverter())->convert($source));

        self::assertStringContainsString($expected, (string)$html, $source);
    }
}
