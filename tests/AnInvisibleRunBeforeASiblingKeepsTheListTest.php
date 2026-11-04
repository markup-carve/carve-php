<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Comments and definitions between list items read as if they were not there (CARVE-P9-013 property 3),
 * also after a span opened on the item's marker line and before a nested list's sibling.
 */
final class AnInvisibleRunBeforeASiblingKeepsTheListTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'a closed marker-line span before a column-0 comment' => ["- %%%\n%%%\n%% z\n- c\n", "<ul>\n  <li></li>\n  <li>c</li>\n</ul>"];
        yield 'a blank in that span (corpus 945-2)' => ["- %%%\n\n%%%\n%% z\n- c\n", "<ul>\n  <li></li>\n  <li>c</li>\n</ul>"];
        yield 'a definition after the closer' => ["- %%%\n%%%\n[s]: /v\n- c\n", "<ul>\n  <li></li>\n  <li>c</li>\n</ul>"];
        yield 'a task box before the span' => ["- [ ] %%%\n\n%%%\n- [ ] c\n", "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled> </li>\n  <li><input type=\"checkbox\" disabled aria-label=\"c\"> c</li>\n</ul>"];
        yield 'an ordered item' => ["1. %%%\n\n%%%\n%% z\n2. c\n", "<ol>\n  <li></li>\n  <li>c</li>\n</ol>"];
        yield 'inside a block quote' => ["> - %%%\n>\n> %%%\n> %% z\n> - c\n", "<blockquote>\n  <ul>\n    <li></li>\n    <li><p>c</p></li>\n  </ul>\n</blockquote>"];
        yield 'a nested list after a blank and a comment' => ["- p\n  - a\n    b\n\n  %% z\n  - c\n", "<ul>\n  <li>p\n    <ul>\n      <li><p>a\nb</p></li>\n      <li><p>c</p></li>\n    </ul>\n  </li>\n</ul>"];
        yield 'a nested list after a blank and a definition' => ["- p\n  - a\n    b\n\n  [s]: /v\n  - c\n", "<ul>\n  <li>p\n    <ul>\n      <li><p>a\nb</p></li>\n      <li><p>c</p></li>\n    </ul>\n  </li>\n</ul>"];
        yield 'a nested list after a closed span and a comment' => ["- p\n  - %%%\n    %%%\n\n  %% z\n  - c\n", "<ul>\n  <li>p\n    <ul>\n      <li></li>\n      <li><p>c</p></li>\n    </ul>\n  </li>\n</ul>"];
    }

    #[DataProvider('documents')]
    public function testRender(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
