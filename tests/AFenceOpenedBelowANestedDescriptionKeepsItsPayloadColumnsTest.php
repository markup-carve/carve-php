<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AFenceOpenedBelowANestedDescriptionKeepsItsPayloadColumnsTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'in a description' => [
                ":: t\n: :: t\n  : ```\n    code\n   ```\n\n    b\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <dl>\n      <dt>t</dt>\n      <dd>\n        <pre><code>code\n</code></pre>\n"
                . "      </dd>\n    </dl>\n    <pre><code>\n b\n</code></pre>\n  </dd>\n</dl>",
            ],
            'in a list item' => [
                "- :: t\n  : :: t\n    : ```\n      code\n     ```\n\n      b\n",
                "<ul>\n  <li>\n    <dl>\n      <dt>t</dt>\n      <dd>\n        <dl>\n          <dt>t</dt>\n          <dd>\n"
                . "            <pre><code>code\n</code></pre>\n          </dd>\n        </dl>\n        <pre><code>\n b\n</code></pre>\n"
                . "      </dd>\n    </dl>\n  </li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('shapes')]
    public function testThePayloadKeepsItsColumnPastTheOpener(string $source, string $expected): void
    {
        // carve-php#4673: the payload line was stripped by the released block's base a second time.
        self::assertSame($expected, rtrim((new CarveConverter())->convert($source), "\n"));
    }
}
