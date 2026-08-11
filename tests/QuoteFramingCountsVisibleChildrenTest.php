<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A block quote with ONE child that renders something is compact; with several
 * it is expanded. A comment (PART 9 section 4.13) and a raw block for another
 * target both render '', and counting such a child pushed a single-paragraph
 * quote into the expanded form (carve#1106).
 *
 * The oracle and carve-js produce the compact form. The list-item renderer here
 * already ignored an invisible child; the quote renderer counted it.
 */
class QuoteFramingCountsVisibleChildrenTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = CarveConverter::create();
    }

    private function html(string $source): string
    {
        return rtrim($this->converter->convert($source), "\n");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invisibleFirstShapes(): array
    {
        return [
            'a line comment first' => ["> %% c\n> y\n"],
            'a line comment second' => ["> y\n> %% c\n"],
            'a comment fence' => ["> %%%\n> c\n> %%%\n> y\n"],
            'a raw block for another target' => ["> ```=latex\n> \\x\n> ```\n> y\n"],
        ];
    }

    #[DataProvider('invisibleFirstShapes')]
    public function testAnInvisibleChildLeavesTheQuoteCompact(string $source): void
    {
        $this->assertSame('<blockquote><p>y</p></blockquote>', $this->html($source));
    }

    /**
     * BOUNDS. None of these moves when the visible-child filter is reverted -
     * they pin what the change must not touch rather than proving it.
     */
    public function testTheShapesThatAlreadyHeldAreUnchanged(): void
    {
        $this->assertSame('<blockquote><p>x</p></blockquote>', $this->html("> x\n"));
        $this->assertSame(
            "<blockquote>\n  <p>a</p>\n  <p>b</p>\n</blockquote>",
            $this->html("> a\n>\n> b\n"),
        );
        $this->assertSame("<blockquote>\n\n</blockquote>", $this->html("> %% c\n"));
        $this->assertSame("<ul>\n  <li>y</li>\n</ul>", $this->html("- %% c\n  y\n"));
    }

    /**
     * The first attempt tested emptiness by rendering the child inside the test
     * while the expanded branch rendered the same children again. That doubles
     * the work at every nesting level - a 20-deep quote went from under a
     * millisecond to six seconds.
     *
     * A RATIO, not a wall-clock bound: doubling the depth may not multiply the
     * cost by more than a small factor. Exponential would be several thousand.
     */
    public function testANestedQuoteIsRenderedOncePerLevel(): void
    {
        $nest = static function (int $depth): string {
            $s = 'x';
            for ($i = 0; $i < $depth; $i++) {
                $s = implode("\n", array_map(static fn (string $l): string => '> ' . $l, explode("\n", $s)));
            }

            return $s . "\n";
        };

        $time = function (int $depth) use ($nest): float {
            $src = $nest($depth);
            $this->converter->convert($src);
            $start = microtime(true);
            for ($i = 0; $i < 20; $i++) {
                $this->converter->convert($src);
            }

            return microtime(true) - $start;
        };

        $shallow = max($time(16), 0.001);
        $deep = $time(32);

        $this->assertLessThan(10.0, $deep / $shallow);
    }
}
