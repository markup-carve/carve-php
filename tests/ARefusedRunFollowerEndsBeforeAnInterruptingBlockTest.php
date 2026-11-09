<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ARefusedRunFollowerEndsBeforeAnInterruptingBlockTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'description heading' => [
            ":: t\n: +\n  {.x\n  .y}\n  ```\n   # h\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>{.x\n.y}\n<code></code></p>\n    <h1 id=\"h\">h</h1>\n  </dd>\n</dl>\n",
        ];

        yield 'description tag remains literal' => [
            ":: t\n: +\n  {#a\n  .b}\n  ```\n   # h\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>{#a\n.b}\n<code></code></p>\n    <h1 id=\"h\">h</h1>\n  </dd>\n</dl>\n",
        ];

        yield 'note heading' => [
            "[^a]: +\n  {.x\n  .y}\n  ```\n   # h\n\nsee[^a]\n",
            "<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>{.x\n.y}\n<code></code></p>\n      <h1 id=\"h\">h</h1>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>\n",
        ];
    }

    #[DataProvider('cases')]
    public function testInterruptingBlockAfterForcedFollower(string $source, string $expected): void
    {
        self::assertSame(rtrim($expected, "\n"), (new CarveConverter())->convert($source));
    }
}
