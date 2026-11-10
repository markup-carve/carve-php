<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A task item's marker-line attribute run is measured from its `{` source column, past the box (CARVE-P2-035, CARVE-P9-083, carve#5777).
 */
final class ATaskBoxPrecedesAMarkerLineAttributeRunTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function shapes(): iterable
    {
        yield 'a continuation at the brace column refuses the run' => [
            "- [ ] {.x\n      .y}\nz\n",
            "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled aria-label=\"{.x .y} z\"> {.x\n.y}\nz</li>\n</ul>",
        ];

        yield 'a continuation inside the box columns refuses the run' => [
            "- [x] {#i\n    .y}\nz\n",
            "<ul class=\"task-list\">\n  <li data-task-state=\"x\"><input type=\"checkbox\" checked disabled aria-label=\"{#i .y} z\"> {<span class=\"tag\"><strong>#i</strong></span>\n.y}\nz</li>\n</ul>",
        ];

        yield 'a continuation past the brace column continues the run' => [
            "- [ ] {.x\n       .y}\nz\n",
            "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled> </li>\n</ul>\n<p>z</p>",
        ];

        yield 'quoted task item' => [
            "> - [ ] {.x\n>       .y}\n> z\n",
            "<blockquote>\n  <ul class=\"task-list\">\n    <li><input type=\"checkbox\" disabled aria-label=\"{.x .y} z\"> {.x\n.y}\nz</li>\n  </ul>\n</blockquote>",
        ];
    }

    #[DataProvider('shapes')]
    public function testShape(string $source, string $html): void
    {
        $this->assertSame($html, trim((new CarveConverter())->convert($source)));
    }
}
