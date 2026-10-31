<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Expected HTML is the spec model's for the rulings each case names.
 */
final class DeepCheckInlineRulingsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'a comment counts as nothing for a single quote (carve#4552)' => ["{%%}'word", '<p>‘word</p>'];
        yield 'a comment counts as nothing for a double quote (carve#4552)' => ['{%%}"q"', '<p>“q”</p>'];
        yield 'text before the comment still closes (carve#4552)' => ["a{%%}'word", '<p>a’word</p>'];
        yield 'a literal bracket keeps its dashes (carve#4551)' => ['[--x] y', '<p>[--x] y</p>'];
        yield 'a parenthesis still converts them (carve#4551)' => ['(--x) y', '<p>(–x) y</p>'];
        yield 'a cell destination reads an escaped pipe (carve#4549)' => ['| [x](/a\|b) |', "<table>\n  <tbody>\n    <tr><td><a href=\"/a|b\">x</a></td></tr>\n  </tbody>\n</table>"];
        yield 'a destination outside a cell keeps it (carve#4549)' => ['[x](/a\|b)', '<p><a href="/a\|b">x</a></p>'];
        yield 'a blanked style keeps its alignment (carve#4548)' => ["{style=\"url(x)\" align=right}\np", '<p style="text-align: right;">p</p>'];
        yield 'a cloned heading drops its ids (carve#4554)' => ["# *A*{#z}\n\n</#A>", "<section id=\"A\">\n  <h1><strong id=\"z\">A</strong></h1>\n  <p><a href=\"#A\"><strong>A</strong></a></p>\n</section>"];
        yield 'a decomposed reference finds its heading (carve#4574)' => ["# Cafe\u{301}\n\n</#Cafe\u{301}>", "<section id=\"Caf\u{E9}\">\n  <h1>Cafe\u{301}</h1>\n  <p><a href=\"#Caf\u{E9}\">Cafe\u{301}</a></p>\n</section>"];
        yield 'an abbreviation stays out of a mention (#4362)' => ["*[A]: x\n\n@A and !`A` and `A`", '<p><span class="mention"><strong>@A</strong></span> and A and <code>A</code></p>'];
        yield 'a tag is still scanned (#4362)' => ["*[A]: x\n\n#A", '<p><span class="tag"><strong>#<abbr title="x">A</abbr></strong></span></p>'];
        yield 'a continuation cell takes a header a span marker extends (carve#4566)' => ["|= a | < |\n+ | | c |", "<table>\n  <thead>\n    <tr><th scope=\"col\" colspan=\"2\">a</th><th scope=\"col\">c</th></tr>\n  </thead>\n</table>"];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function cellDestinations(): iterable
    {
        yield 'a pipe' => ['| [x](/a\\|b) |'];
        yield 'a backslash and a pipe' => ['| [x](/a\\\\\\|b) |'];
        yield 'two backslashes and a pipe' => ['| [x](/a\\\\\\\\\\|b) |'];
        yield 'an image' => ['| ![x](/a\\|b) |'];
        yield 'an unpaired parenthesis and a pipe' => ['| [x](/a\\(\\|b) |'];
    }

    #[DataProvider('cellDestinations')]
    public function testTheFormatterKeepsACellDestination(string $source): void
    {
        $formatted = CarveConverter::toCarve($source);

        self::assertSame((new CarveConverter())->convert($source), (new CarveConverter())->convert($formatted));
        self::assertSame($formatted, CarveConverter::toCarve($formatted));
    }

    #[DataProvider('documents')]
    public function testRender(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
