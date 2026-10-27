<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DescriptionBelowTermHostEndsItemTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function rows(): array
    {
        return [
            'item' => [
                "- a\n  ::: box\n  :: sub\n: def\n",
                "<ul>\n  <li>a\n    <div class=\"box\">\n      <dl>\n        <dt>sub</dt>\n      </dl>\n    </div>\n  </li>\n</ul>\n<p>: def</p>\n",
            ],
            'nested item' => [
                "- a\n  - b\n    ::: box\n    :: sub\n: def\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <div class=\"box\">\n          <dl>\n            <dt>sub</dt>\n          </dl>\n        </div>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>: def</p>\n",
            ],
        ];
    }

    #[DataProvider('rows')]
    public function testDescriptionLeavesItsHost(string $source, string $expected): void
    {
        self::assertSame(preg_replace('/\n\z/', '', $expected), CarveConverter::create()->convert($source));
    }
}
