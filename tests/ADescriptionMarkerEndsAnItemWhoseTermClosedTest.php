<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#5320: a `: ` line at an item's marker column ends the item once a term or description in it has closed.
 */
final class ADescriptionMarkerEndsAnItemWhoseTermClosedTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideShapes(): array
    {
        return [
            'an open term in the nested item' => [
                "- - a\n    :: t\n  : b\n: c\n",
                "<ul>\n  <li>\n    <ul>\n      <li>a\n        <dl>\n          <dt>t</dt>\n        </dl>\n      </li>\n    </ul>\n    : b\n  </li>\n</ul>\n<p>: c</p>",
            ],
            'a term directly in the item, then a paragraph' => [
                "- o\n  :: t\n\n  para\n: c\n",
                "<ul>\n  <li><p>o</p>\n    <dl>\n      <dt>t</dt>\n    </dl>\n    <p>para</p>\n  </li>\n</ul>\n<p>: c</p>",
            ],
            'a term on the item marker line' => [
                "- :: t\n   a\n  : x\n: z\n",
                "<ul>\n  <li>\n    <dl>\n      <dt>t</dt>\n    </dl>\n    a\n: x\n  </li>\n</ul>\n<p>: z</p>",
            ],
            'a marker-line task item' => [
                "- - [ ] a\n        :: t\n  : b\n: c\n",
                "<ul>\n  <li>\n    <ul class=\"task-list\">\n      <li><input type=\"checkbox\" disabled aria-label=\"a\"> a\n        <dl>\n          <dt>t</dt>\n        </dl>\n      </li>\n    </ul>\n    : b\n  </li>\n</ul>\n<p>: c</p>",
            ],
            'a list after a nested description keeps the lazy line' => [
                "- - a\n    :: t\n    : u\n    1. n\n: c\n",
                "<ul>\n  <li>\n    <ul>\n      <li>a\n        <dl>\n          <dt>t</dt>\n          <dd>u</dd>\n        </dl>\n        <ol>\n          <li>n\n: c</li>\n        </ol>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'a quote after a closed description keeps the lazy line' => [
                "- - a\n    :: t\n    : u\n  > q\n      w\n: b\n",
                "<ul>\n  <li>\n    <ul>\n      <li>a\n        <dl>\n          <dt>t</dt>\n          <dd>u</dd>\n        </dl>\n      </li>\n    </ul>\n    <blockquote><p>q\nw\n: b</p></blockquote>\n  </li>\n</ul>",
            ],
            'a fence and a description after the term end the item (carve#5341)' => [
                "- a\n  :: t\n  ```\n  :: x\n  ```\n  : w\n: z\n",
                "<ul>\n  <li>a\n    <dl>\n      <dt>t</dt>\n    </dl>\n    <pre><code>:: x\n</code></pre>\n    : w\n  </li>\n</ul>\n<p>: z</p>",
            ],
            'a stanza after the term ends the item' => [
                "- a\n  :: t\n  :::\n  in\n  :::\n: z\n",
                "<ul>\n  <li>a\n    <dl>\n      <dt>t</dt>\n    </dl>\n    <div>\n      <p>in</p>\n    </div>\n  </li>\n</ul>\n<p>: z</p>",
            ],
            'a note after the term keeps the lazy line' => [
                "- a\n  :: t\n  [^n]: note\n: z\n",
                "<ul>\n  <li>a\n    <dl>\n      <dt>t</dt>\n    </dl>\n  </li>\n</ul>",
            ],
            'a new item after the term keeps the lazy line' => [
                "- o\n  :: t\n  - n\n: c\n",
                "<ul>\n  <li>o\n    <dl>\n      <dt>t</dt>\n    </dl>\n    <ul>\n      <li>n\n: c</li>\n    </ul>\n  </li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('provideShapes')]
    public function testMarker(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
