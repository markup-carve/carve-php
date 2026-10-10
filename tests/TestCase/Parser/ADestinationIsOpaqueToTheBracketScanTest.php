<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CARVE-P3-001 skips the interior of every construct whose content is LITERAL,
 * and states that every future literal construct joins the set. A destination
 * resolves no escapes, so a `]` inside one is content and not the close of an
 * outstanding bracket run (carve#2854, carve-php#3046).
 */
class ADestinationIsOpaqueToTheBracketScanTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function opaqueDestinations(): array
    {
        return [
            // The two shapes the Djot importer writes, where the surrounding `[` is literal text.
            'an outstanding bracket before a destination holding the close' => [
                'a [[http\:\/\/u\/\]](http://u/])',
                '<p>a [<a href="http://u/]">http://u/]</a></p>',
            ],
            'a link wrapped in a literal bracket pair' => [
                '[x [http\:\/\/u\/a\]b](http://u/a]b) y]',
                '<p>[x <a href="http://u/a]b">http://u/a]b</a> y]</p>',
            ],
            'an image destination holding the close' => [
                'a [![alt](http://u/a]b)',
                '<p>a [<img src="http://u/a]b" alt="alt"></p>',
            ],
            'a title and a destination both holding the close' => [
                'a [[t](http://u/a]b "ti]tle")',
                '<p>a [<a href="http://u/a]b" title="ti]tle">t</a></p>',
            ],
            'balanced parentheses inside the destination' => [
                'a [[t](http://u/a(b])c)',
                '<p>a [<a href="http://u/a(b])c">t</a></p>',
            ],
            // A title closes the destination, so its own parenthesis must not end the
            // skip early and leave a later close exposed.
            'a parenthesis and then a close inside the title' => [
                'a [[t](http://u/x "a)b]c")',
                '<p>a [<a href="http://u/x" title="a)b]c">t</a></p>',
            ],
            'an escaped parenthesis inside the destination' => [
                'a [[t](http://u/a\\)b])',
                '<p>a [<a href="http://u/a)b]">t</a></p>',
            ],
        ];
    }

    #[DataProvider('opaqueDestinations')]
    public function testADestinationCloseDoesNotCloseAnOutstandingBracketRun(string $source, string $expected): void
    {
        $this->assertSame($expected, trim($this->converter->convert($source)));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unaffectedShapes(): array
    {
        return [
            // No outstanding `[`, so the skip never engages. These already worked.
            'a close inside a destination with no outstanding bracket' => [
                '[t](http://u/a]b)',
                '<p><a href="http://u/a]b">t</a></p>',
            ],
            // An opener inside the destination never closed the outer run, so this
            // already worked; it is here so the skip cannot regress it.
            'an unbalanced opener inside the destination' => [
                'a [[t](http://u/a[b)',
                '<p>a [<a href="http://u/a[b">t</a></p>',
            ],
            'balanced brackets inside a destination' => [
                '[t](http://u/a[1]b)',
                '<p><a href="http://u/a[1]b">t</a></p>',
            ],
            'an image destination with no outstanding bracket' => [
                '![alt](http://u/a]b)',
                '<img src="http://u/a]b" alt="alt">',
            ],
            'a close inside a title only' => [
                '[t](http://u/x "ti]tle")',
                '<p><a href="http://u/x" title="ti]tle">t</a></p>',
            ],
            'a reference destination holding the close' => [
                "[t][r] x\n\n[r]: http://u/a]b",
                '<p><a href="http://u/a]b">t</a> x</p>',
            ],
            // The control: a real close still ends link text, and a parenthesized run
            // that is not a destination is not skipped.
            'a close still ends the link text' => [
                '[te]xt](http://u/x)',
                '<p>[te]xt](http://u/x)</p>',
            ],
            'a close still ends a bare bracket run' => [
                '[a]b] and [t](u)',
                '<p>[a]b] and <a href="u">t</a></p>',
            ],
            'a whitespace-flanked run is no destination and is not skipped' => [
                'a [[t]( http://u/] )',
                '<p>a [[t]( http://u/] )</p>',
            ],
        ];
    }

    #[DataProvider('unaffectedShapes')]
    public function testTheSkipLeavesEveryOtherBracketShapeAlone(string $source, string $expected): void
    {
        $this->assertSame($expected, trim($this->converter->convert($source)));
    }
}
