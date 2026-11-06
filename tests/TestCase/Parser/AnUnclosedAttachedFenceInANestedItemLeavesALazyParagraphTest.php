<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#5309: an unclosed `+` attached fence ends at its blank (PART 9 §17 L3),
 * so the paragraph after it takes a lazy line (PART 0 S4) at every item depth.
 */
final class AnUnclosedAttachedFenceInANestedItemLeavesALazyParagraphTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideShapes(): array
    {
        $three = "<ul>\n  <li>\n    <ul>\n      <li>\n        <ul>\n          <li>\n            <pre><code></code></pre>\n"
            . "          </li>\n        </ul>\n        <p>z\ntail</p>\n      </li>\n    </ul>\n  </li>\n</ul>";
        $two = "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code></code></pre>\n      </li>\n    </ul>\n"
            . "    <p>z\ntail</p>\n  </li>\n</ul>";

        return [
            'ticket' => ["- - - +\n    ```\n\n    z\ntail\n", $three],
            'tilde fence' => ["- - - +\n    ~~~\n\n    z\ntail\n", $three],
            'closed fence' => ["- - - +\n    ```\n    ```\n\n    z\ntail\n", $three],
            'two levels' => ["- - +\n  ```\n\n  z\ntail\n", $two],
        ];
    }

    #[DataProvider('provideShapes')]
    public function testTheLineAfterTheParagraphFoldsIntoIt(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testACloserBelowTheHostDoesNotKeepTheFenceOpen(): void
    {
        $html = (new CarveConverter())->convert("- - +\n  ```\n\n  z\ntail\n```\n");

        self::assertStringContainsString("<p>z\ntail</p>", $html);
    }
}
