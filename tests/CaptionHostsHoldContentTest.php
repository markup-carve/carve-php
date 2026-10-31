<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Expected HTML is the spec model's (carve#4568, carve#4573).
 */
final class CaptionHostsHoldContentTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        $figure = "<figure>\n  <img src=\"/i\" alt=\"a\">\n  <figcaption>cap</figcaption>\n</figure>";

        yield 'line comment after an image' => ["![a](/i) %% c\n^ cap", $figure];
        yield 'braced comment glued to an image' => ["![a](/i){%%}\n^ cap", $figure];
        yield 'spaced braced comment stays a paragraph' => ["![a](/i) {% c %}\n^ cap", "<p><img src=\"/i\" alt=\"a\"> \n^ cap</p>"];
        yield 'a reference image defined below' => ["![a][r] %% c\n^ cap\n\n[r]: /i", $figure];
        yield 'an attribute line moves to the figure' => ["{#k}\n![a](/i) %% c\n^ cap", "<figure id=\"k\">\n  <img src=\"/i\" alt=\"a\">\n  <figcaption>cap</figcaption>\n</figure>"];
        yield 'a quote of an empty quote' => ["> >\n^ cap", "<blockquote>\n  <blockquote>\n\n  </blockquote>\n</blockquote>\n<p>^ cap</p>"];
        yield 'a quote of a commented quote' => ["> > %%\n^ cap", "<blockquote>\n  <blockquote>\n\n  </blockquote>\n</blockquote>\n<p>^ cap</p>"];
        yield 'a quote of a bare empty div' => ["> :::\n> :::\n^ cap", "<blockquote>\n  <div>\n\n  </div>\n</blockquote>\n<p>^ cap</p>"];
        yield 'a nested quote with content keeps its caption' => ["> > a\n^ cap", "<figure>\n  <blockquote>\n    <blockquote><p>a</p></blockquote>\n  </blockquote>\n  <figcaption>cap</figcaption>\n</figure>"];
    }

    #[DataProvider('documents')]
    public function testRender(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testTheFormatterKeepsADetachedCaretLineDetached(): void
    {
        $source = "![a](/i) %% c\n\n\n^ cap\n";
        $formatted = CarveConverter::toCarve($source);

        self::assertSame((new CarveConverter())->convert($source), (new CarveConverter())->convert($formatted));
        self::assertSame($formatted, CarveConverter::toCarve($formatted));
    }

    public function testTheFormatterKeepsTheComment(): void
    {
        self::assertSame("![a](/i) %% c\n^ cap\n", CarveConverter::toCarve("![a](/i) %% c\n^ cap\n"));
    }
}
