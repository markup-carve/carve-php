<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A task box is content (CARVE-P0-008): a nested first-block task item takes its follower at its content column (carve-php#4277), and a marker-line child after a box keeps the box's columns (carve-php#4284). Expected HTML is the spec model's.
 */
final class ATaskBoxIsContentForAFirstBlockOrMarkerLineChildTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'nested first-block task item takes its follower (#4277)' => ["- - [ ] +\n    s\n", "<ul>\n  <li>\n    <ul class=\"task-list\">\n      <li><input type=\"checkbox\" disabled aria-label=\"s\"> s</li>\n    </ul>\n  </li>\n</ul>"];
        yield 'follower past the box' => ["- - [ ] +\n      s\n", "<ul>\n  <li>\n    <ul class=\"task-list\">\n      <li><input type=\"checkbox\" disabled aria-label=\"s\"> s</li>\n    </ul>\n  </li>\n</ul>"];
        yield 'checked box' => ["- - [x] +\n     s\n", "<ul>\n  <li>\n    <ul class=\"task-list\">\n      <li data-task-state=\"x\"><input type=\"checkbox\" checked disabled aria-label=\"s\"> s</li>\n    </ul>\n  </li>\n</ul>"];
        yield 'quoted ordered host' => ["> 1. - [ ] +\n>      s\n", "<blockquote>\n  <ol>\n    <li>\n      <ul class=\"task-list\">\n        <li><input type=\"checkbox\" disabled aria-label=\"s\"> s</li>\n      </ul>\n    </li>\n  </ol>\n</blockquote>"];
        yield 'follower below the content column stays out' => ["- - [ ] +\n   s\n", "<ul>\n  <li>\n    <ul class=\"task-list\">\n      <li><input type=\"checkbox\" disabled> </li>\n    </ul>\n    s\n  </li>\n</ul>"];
        yield 'bullet short of the ordered child\'s content column (#4284)' => ["* [ ] 1. p\n       - a\n", "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled> \n    <ol>\n      <li>p\n- a</li>\n    </ol>\n  </li>\n</ul>"];
        yield 'two columns short' => ["* [ ] 1. p\n        - a\n", "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled> \n    <ol>\n      <li>p\n- a</li>\n    </ol>\n  </li>\n</ul>"];
        yield 'in a box column' => ["* [ ] 1. p\n   - a\n", "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled> \n    <ol>\n      <li>p\n- a</li>\n    </ol>\n  </li>\n</ul>"];
        yield 'at the child\'s column opens a list in the item' => ["* [ ] 1. p\n      - a\n", "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled> \n    <ol>\n      <li>p</li>\n    </ol>\n    <ul>\n      <li>a</li>\n    </ul>\n  </li>\n</ul>"];
        yield 'at the item\'s content column' => ["* [ ] 1. p\n  - a\n", "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled> \n    <ol>\n      <li>p</li>\n    </ol>\n    <ul>\n      <li>a</li>\n    </ul>\n  </li>\n</ul>"];
        yield 'below the item\'s content column' => ["* [ ] - p\n - a\n", "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled> \n    <ul>\n      <li>p\n- a</li>\n    </ul>\n  </li>\n</ul>"];
        yield 'sibling of the child in a box column' => ["* [ ] - p\n    - a\n", "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled> \n    <ul>\n      <li>p</li>\n      <li>a</li>\n    </ul>\n  </li>\n</ul>"];
        yield 'sibling of the child at the item\'s content column' => ["* [ ] - p\n  - a\n", "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled> \n    <ul>\n      <li>p</li>\n      <li>a</li>\n    </ul>\n  </li>\n</ul>"];
        yield 'sibling of the child past its column' => ["* [ ] - p\n       - a\n", "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled> \n    <ul>\n      <li>p</li>\n      <li>a</li>\n    </ul>\n  </li>\n</ul>"];
        yield 'quoted' => ["> * [ ] 1. p\n>        - a\n", "<blockquote>\n  <ul class=\"task-list\">\n    <li><input type=\"checkbox\" disabled> \n      <ol>\n        <li>p\n- a</li>\n      </ol>\n    </li>\n  </ul>\n</blockquote>"];
        yield 'item host' => ["- o\n  * [ ] 1. p\n         - a\n", "<ul>\n  <li>o\n    <ul class=\"task-list\">\n      <li><input type=\"checkbox\" disabled> \n        <ol>\n          <li>p\n- a</li>\n        </ol>\n      </li>\n    </ul>\n  </li>\n</ul>"];
    }

    #[DataProvider('documents')]
    public function testTheBoxCountsAsContent(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
