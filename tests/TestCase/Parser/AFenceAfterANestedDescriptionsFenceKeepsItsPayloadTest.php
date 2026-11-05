<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A marker-shaped line inside a fence opened after a nested description's fence is payload, and a marker that
 * ends that fence leaves no blank in it (PART 0 S1, carve-php#4684).
 * Expected output is the spec model's at carve main ce3f1307.
 */
final class AFenceAfterANestedDescriptionsFenceKeepsItsPayloadTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'a marker line as payload' => [
            ":: t\n: 1. :: t\n     : ```php\n       ```\n     ```\n      - x\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <ol>\n      <li>\n        <dl>\n          <dt>t</dt>\n          <dd>\n"
            . "            <pre><code class=\"language-php\"></code></pre>\n          </dd>\n        </dl>\n"
            . "        <pre><code> - x\n</code></pre>\n      </li>\n    </ol>\n  </dd>\n</dl>",
        ];

        yield 'a sibling marker ends the fence' => [
            ":: t\n: - :: t\n    : ```\n      ```\n    ```\n   - x\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>\n        <dl>\n          <dt>t</dt>\n          <dd>\n"
            . "            <pre><code></code></pre>\n          </dd>\n        </dl>\n"
            . "        <pre><code></code></pre>\n      </li>\n      <li>x</li>\n    </ul>\n  </dd>\n</dl>",
        ];

        yield 'a marker after the inner payload' => [
            ":: t\n: - :: t\n    : ```\n      code\n  - y\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>\n        <dl>\n          <dt>t</dt>\n          <dd>\n"
            . "            <pre><code>code\n</code></pre>\n          </dd>\n        </dl>\n      </li>\n      <li>y</li>\n    </ul>\n  </dd>\n</dl>",
        ];
    }

    #[DataProvider('documents')]
    public function testTheFenceKeepsItsPayload(string $source, string $html): void
    {
        self::assertSame($html, trim((new CarveConverter())->convert($source)));
    }
}
