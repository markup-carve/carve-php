<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A refused brace run's follower stays its paragraph's text in nested items, and a later lazy line continues it (CARVE-P2-035, PART 0 S4, carve#5757, carve#5758).
 */
final class RefusedRunFollowerInNestedItemTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function shapes(): iterable
    {
        yield 'a nested refused run keeps its heading follower and the later lazy line' => [
            "- - {.x\n   }\n    # h\nplain\n",
            "<ul>\n  <li>\n    <ul>\n      <li>{.x\n}\n# h\nplain</li>\n    </ul>\n  </li>\n</ul>",
        ];

        yield 'a nested refused run keeps its fence-shaped follower as inline code' => [
            "- p\n  - {.x\n   }\n    ```\nplain\n",
            "<ul>\n  <li>p\n    <ul>\n      <li>{.x\n}\n<code>\nplain</code></li>\n    </ul>\n  </li>\n</ul>",
        ];

        yield 'a refused run on a nested marker line leaves the next sibling in the same list' => [
            "- p\n  - {.x\n   }\n  - q\n",
            "<ul>\n  <li>p\n    <ul>\n      <li>{.x\n}</li>\n      <li>q</li>\n    </ul>\n  </li>\n</ul>",
        ];

        yield 'a refused comment run leaves the next sibling in the same list' => [
            "- p\n  - {% a\n   %}\n  - q\n",
            "<ul>\n  <li>p\n    <ul>\n      <li>{% a\n%}</li>\n      <li>q</li>\n    </ul>\n  </li>\n</ul>",
        ];

        yield 'a note-definition follower in a marker-chain item keeps the later lazy line' => [
            "- - {% a\n    %}\n    [^m]: n\nplain\n",
            "<ul>\n  <li>\n    <ul>\n      <li>{% a\n%}\n[^m]: n\nplain</li>\n    </ul>\n  </li>\n</ul>",
        ];

        yield 'a lone plus after a lazy closer is the follower, not a continuation marker' => [
            "- {.x\n}\n+\nz\n",
            "<ul>\n  <li>{.x\n}\n+\nz</li>\n</ul>",
        ];

        yield 'a lone plus after a lazy comment closer is text' => [
            "- {% a\n%}\n+\nz\n",
            "<ul>\n  <li>{% a\n%}\n+\nz</li>\n</ul>",
        ];
    }

    #[DataProvider('shapes')]
    public function testShape(string $source, string $html): void
    {
        $this->assertSame($html, trim((new CarveConverter())->convert($source)));
    }
}
