<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Runs the ownership walk's kept-line reading (carve#5191) does not take: a description body's run and a comment run.
 * Expected output is the spec model's at carve main 9bc03fe5.
 */
final class ARefusedRunReaderLeavesOtherRunsAsTheyWereTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function runs(): iterable
    {
        yield 'a comment run in a description keeps its text before a fence follower' => [
            ":: t\n: d\n  {% x\n  y %}\n  ~~~\n  more\n",
            "<dl>\n  <dt>t</dt>\n  <dd>d\n{% x\ny %}\n~~~\nmore</dd>\n</dl>",
        ];

        yield 'an attribute run in a description stays literal' => [
            ":: t\n: d\n  {.c\n  #i}\n  ```\n",
            "<dl>\n  <dt>t</dt>\n  <dd>d\n{.c\n#i}\n<code></code></dd>\n</dl>",
        ];

        yield 'a comment run does not reach a sibling item' => [
            "- a\n  - {% x\n  - y %}\n  - c\n",
            "<ul>\n  <li>a\n    <ul>\n      <li>{% x</li>\n      <li>y %}</li>\n      <li>c</li>\n    </ul>\n  </li>\n</ul>",
        ];
    }

    #[DataProvider('runs')]
    public function testTheRunReadsAsBefore(string $source, string $html): void
    {
        self::assertSame($html, trim((new CarveConverter())->convert($source)));
    }

    #[Group('scaling')]
    public function testARunOfOneLineAttributesStaysLinear(): void
    {
        $short = str_repeat("{#x}\n", 1000) . "p\n";
        $long = str_repeat("{#x}\n", 8000) . "p\n";
        $time = static function (string $source): float {
            $start = hrtime(true);
            (new CarveConverter())->convert($source);

            return (hrtime(true) - $start) / 1e6;
        };
        $time($short);
        self::assertLessThan(8 * 4 * max(1.0, $time($short)), $time($long));
    }
}
