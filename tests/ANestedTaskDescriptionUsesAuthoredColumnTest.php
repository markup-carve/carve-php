<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A description at a nested task item's authored content column follows its term.
 */
final class ANestedTaskDescriptionUsesAuthoredColumnTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'plain parent' => [
                "- a\n  - [ ] item\n        :: t\n        : u\n",
                "<dt>t</dt>\n          <dd>u</dd>",
            ],
            'task parent' => [
                "- [ ] outer\n      - [x] item\n            :: t\n            : u\n",
                "<dt>t</dt>\n          <dd>u</dd>",
            ],
            'second entry' => [
                "- a\n  - [ ] item\n        :: t\n        : u\n        :: v\n        : w\n",
                "<dt>v</dt>\n          <dd>w</dd>",
            ],
            'shifted term on item marker' => [
                "  - :: u\n    : e\n",
                "<dt>u</dt>\n      <dd>e</dd>",
            ],
            'description deeper than term' => [
                "  - [ ] item\n      :: t\n       : u\n",
                "<dt>t</dt>\n      <dd>u</dd>",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testDescriptionFollowsTerm(string $source, string $expected): void
    {
        self::assertStringContainsString($expected, (new CarveConverter())->convert($source));
    }

    public function testDedentedDescriptionReturnsToParent(): void
    {
        $source = "- a\n    - [ ] item\n      :: t\n     : u\n";
        $expected = "<ul>\n  <li>a\n    <ul class=\"task-list\">\n      <li><input type=\"checkbox\" disabled aria-label=\"item\"> item\n        <dl>\n          <dt>t</dt>\n        </dl>\n      </li>\n    </ul>\n    : u\n  </li>\n</ul>";

        self::assertSame($expected, rtrim((new CarveConverter())->convert($source), "\n"));
    }
}
