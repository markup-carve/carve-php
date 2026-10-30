<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A line that misses a quoted fence's owner ends the fence (PART 0 S1); with no
 * paragraph open it folds nowhere and closes the containers below its column.
 */
final class AnUnprefixedLineAfterAQuotedFenceClosesItsContainersTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function hosts(): array
    {
        return [
            'item' => ["- > ```\n  > x\nafter\n", "<ul>\n  <li>\n    <blockquote>\n      <pre><code>x\n</code></pre>\n    </blockquote>\n  </li>\n</ul>\n<p>after</p>"],
            'nested item' => ["- a\n  - > ~~~\n    > x\nafter\n", "<ul>\n  <li>a\n    <ul>\n      <li>\n        <blockquote>\n          <pre><code>x\n</code></pre>\n        </blockquote>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>after</p>"],
            'description' => [":: t\n: > ```\n  > x\nafter\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <blockquote>\n      <pre><code>x\n</code></pre>\n    </blockquote>\n  </dd>\n</dl>\n<p>after</p>"],
            'description caption' => [":: t\n: > ```\n  > x\n^ cap\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <blockquote>\n      <pre><code>x\n</code></pre>\n    </blockquote>\n  </dd>\n</dl>\n<p>^ cap</p>"],
            'line block keeps its lazy line' => ["> ::: |\n> # h\n>  x\nafter\n", "<blockquote>\n  <div class=\"line-block\">\n    <p># h<br>\n&nbsp;x<br>\nafter</p>\n  </div>\n</blockquote>"],
        ];
    }

    #[DataProvider('hosts')]
    public function testItRendersLikeTheModel(string $source, string $html): void
    {
        self::assertSame($html, CarveConverter::create()->convert($source));
    }
}
