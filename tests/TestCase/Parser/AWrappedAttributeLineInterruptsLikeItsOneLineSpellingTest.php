<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A wrapped attribute block applies T once across its source extent (PART 0),
 * so `{.c` / `}` below an open paragraph reads like the one-line `{.c}`.
 */
final class AWrappedAttributeLineInterruptsLikeItsOneLineSpellingTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provider(): array
    {
        return [
            'after an item, before a fence' => ["- a\n{.c\n}\n~~~\ny\n~~~\n", "- a\n{.c}\n~~~\ny\n~~~\n"],
            'after an item, before a paragraph' => ["- a\n{.c\n}\npara\n", "- a\n{.c}\npara\n"],
            'indented closer' => ["- a\n{.c\n  }\npara\n", "- a\n{.c}\npara\n"],
            'two classes' => ["- a\n{.c\n.d}\npara\n", "- a\n{.c .d}\npara\n"],
            'after a quote' => ["> a\n{.c\n}\npara\n", "> a\n{.c}\npara\n"],
            'after a refused nested plus' => ["- - ```\n  +\n{.c\n}\n~~~\ny\n~~~\n", "- - ```\n  +\n{.c}\n~~~\ny\n~~~\n"],
        ];
    }

    #[DataProvider('provider')]
    public function testTheWrappedFormRendersLikeTheOneLineForm(string $wrapped, string $oneLine): void
    {
        $this->assertSame((new CarveConverter())->convert($oneLine), (new CarveConverter())->convert($wrapped));
    }

    public function testTheClassReachesTheFenceAfterAnItem(): void
    {
        $this->assertSame(
            "<ul>\n  <li>a</li>\n</ul>\n<pre class=\"c\"><code>y\n</code></pre>",
            trim((new CarveConverter())->convert("- a\n{.c\n}\n~~~\ny\n~~~\n")),
        );
    }
}
