<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AFencePayloadKeepsAuthoredIndentAfterLiteralPlusTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function documents(): array
    {
        $lead = "- - +\n  ```\n  ```\n  ```\n\n  # h\n  +\n";

        return [
            'two residual spaces' => [
                $lead . "    # x\n",
                "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code></code></pre>\n      </li>\n    </ul>\n    <pre><code>\n# h\n+\n  # x\n</code></pre>\n  </li>\n</ul>",
            ],
            'three residual spaces' => [
                $lead . "     # x\n",
                "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code></code></pre>\n      </li>\n    </ul>\n    <pre><code>\n# h\n+\n   # x\n</code></pre>\n  </li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('documents')]
    public function testKeepsResidualIndent(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
