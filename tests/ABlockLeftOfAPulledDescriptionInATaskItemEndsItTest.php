<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#4832: in a task item, a block after a pulled `: +` paragraph opens in the description only at its content column.
 */
final class ABlockLeftOfAPulledDescriptionInATaskItemEndsItTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideShapes(): array
    {
        return [
            'a heading at the task item column ends the description' => [
                "- [ ] a\n      :: t\n      : +\n      w\n      # H\n",
                "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled aria-label=\"a\"> a\n    <dl>\n      <dt>t</dt>\n      <dd>w</dd>\n    </dl>\n    <h1 id=\"H\">H</h1>\n  </li>\n</ul>",
            ],
            'a table at a nested task item column ends the description' => [
                "* b\n  - [ ] a\n        :: t\n        : +\n        w\n        | a |\n  :: z\n",
                "<ul>\n  <li>b\n    <ul class=\"task-list\">\n      <li><input type=\"checkbox\" disabled aria-label=\"a\"> a\n        <dl>\n          <dt>t</dt>\n          <dd>w</dd>\n        </dl>\n        <table>\n          <tbody>\n            <tr><td>a</td></tr>\n          </tbody>\n        </table>\n      </li>\n    </ul>\n    <dl>\n      <dt>z</dt>\n    </dl>\n  </li>\n</ul>",
            ],
            'a heading at the description content column opens in it' => [
                "- [ ] a\n      :: t\n      : +\n      w\n        # H\n",
                "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled aria-label=\"a\"> a\n    <dl>\n      <dt>t</dt>\n      <dd>\n        <p>w</p>\n        <h1 id=\"H\">H</h1>\n      </dd>\n    </dl>\n  </li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('provideShapes')]
    public function testBlock(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
