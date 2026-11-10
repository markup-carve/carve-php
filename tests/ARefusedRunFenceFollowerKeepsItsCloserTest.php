<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ARefusedRunFenceFollowerKeepsItsCloserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield '{.x' => [":: t\n: +\n  {.x\n  .y}\n  ```\n  ```\n\nend\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>{.x\n.y}</p>\n    <pre><code></code></pre>\n  </dd>\n</dl>\n<p>end</p>"];
        yield '{#a' => [":: t\n: +\n  {#a\n  .b}\n  ```\n  ```\n\nend\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>{<span class=\"tag\"><strong>#a</strong></span>\n.b}</p>\n    <pre><code></code></pre>\n  </dd>\n</dl>\n<p>end</p>"];
        yield '# heading' => [":: t\n: +\n  {.x\n  .y}\n  ```\n  # heading\n  ```\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>{.x\n.y}</p>\n    <pre><code># heading\n</code></pre>\n  </dd>\n</dl>"];
        yield '- item' => [":: t\n: +\n  {.x\n  .y}\n  ```\n  - item\n  ```\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>{.x\n.y}</p>\n    <pre><code>- item\n</code></pre>\n  </dd>\n</dl>"];
    }

    #[DataProvider('cases')]
    public function testCloserIsPartOfParagraph(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
