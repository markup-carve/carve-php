<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#4814: a nested task item's body sits at its bullet's content column, so its description lines are not band lines.
 */
final class ANestedTaskItemKeepsItsDescriptionTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideShapes(): array
    {
        return [
            'the ticket reproduction' => [
                "- a\n  - [ ] item\n        :: t\n        : u\n",
                "<ul>\n  <li>a\n    <ul class=\"task-list\">\n      <li><input type=\"checkbox\" disabled aria-label=\"item\"> item\n        <dl>\n          <dt>t</dt>\n          <dd>u</dd>\n        </dl>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'a task item in a task item' => [
                "- [ ] item\n      - [ ] item\n            :: t\n            : u\n",
                "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled aria-label=\"item\"> item\n    <ul class=\"task-list\">\n      <li><input type=\"checkbox\" disabled aria-label=\"item\"> item\n        <dl>\n          <dt>t</dt>\n          <dd>u</dd>\n        </dl>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'a second term' => [
                "- a\n  - [x] item\n        :: t\n        :: s\n        : u\n",
                "<ul>\n  <li>a\n    <ul class=\"task-list\">\n      <li data-task-state=\"x\"><input type=\"checkbox\" checked disabled aria-label=\"item\"> item\n        <dl>\n          <dt>t</dt>\n          <dt>s</dt>\n          <dd>u</dd>\n        </dl>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'a description after item text' => [
                "1. a\n   * [ ] item\n         text\n         : u\n",
                "<ol>\n  <li>a\n    <ul class=\"task-list\">\n      <li><input type=\"checkbox\" disabled aria-label=\"item text : u\"> item\ntext\n: u</li>\n    </ul>\n  </li>\n</ol>",
            ],
            'a line in the band stays lazy' => [
                "- a\n  - [ ] item\n        :: t\n      : u\n",
                "<ul>\n  <li>a\n    <ul class=\"task-list\">\n      <li><input type=\"checkbox\" disabled aria-label=\"item\"> item\n        <dl>\n          <dt>t</dt>\n          <dd>u</dd>\n        </dl>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('provideShapes')]
    public function testMarker(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
