<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ARefusedBraceRunDescriptionBoundaryTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function examples(): iterable
    {
        yield '{.x' => [":: t\n: +\n  {.x\n  .y}\n  ```\n  wa\n> q\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>{.x\n.y}</p>\n    <pre><code>wa\n</code></pre>\n  </dd>\n</dl>\n<blockquote><p>q</p></blockquote>"];
        yield '{#a' => [":: t\n: +\n  {#a\n  .b}\n  ```\n  wa\n> q\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>{<span class=\"tag\"><strong>#a</strong></span>\n.b}</p>\n    <pre><code>wa\n</code></pre>\n  </dd>\n</dl>\n<blockquote><p>q</p></blockquote>"];
    }

    #[DataProvider('examples')]
    public function testUnclosedSpanEndsAtDescriptionBoundary(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
