<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Expected HTML is the spec model's (ruling on carve#4545, PART 0 S4 and PART 9 section 11 N1).
 */
final class AMarkerBetweenAnItemsColumnsMatchesOnEveryAxisTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'a task under a plain item' => ["- a\n - [ ] b\n", "<ul>\n  <li>a\n- [ ] b</li>\n</ul>"];
        yield 'a plain item under a task' => ["- [ ] a\n - b\n", "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled aria-label=\"a - b\"> a\n- b</li>\n</ul>"];
        yield 'a letter under a number' => ["1. a\n a. b\n", "<ol>\n  <li>a\na. b</li>\n</ol>"];
        yield 'a roman under a number' => ["1. a\n i. b\n", "<ol>\n  <li>a\ni. b</li>\n</ol>"];
        yield 'a number under a letter' => ["a. a\n 1. b\n", "<ol type=\"a\">\n  <li>a\n1. b</li>\n</ol>"];
        yield 'a sibling still interrupts' => ["- a\n - b\n", "<ul>\n  <li>a</li>\n  <li>b</li>\n</ul>"];
        yield 'at column 0 a task opens a new list' => ["- a\n- [ ] b\n", "<ul>\n  <li>a</li>\n</ul>\n<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled aria-label=\"b\"> b</li>\n</ul>"];
    }

    #[DataProvider('documents')]
    public function testRender(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
