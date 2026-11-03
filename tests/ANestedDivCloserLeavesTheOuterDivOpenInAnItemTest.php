<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ANestedDivCloserLeavesTheOuterDivOpenInAnItemTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideShapes(): array
    {
        $open = "<ul>\n  <li>head\n    <div class=\"box\">\n      <p>a</p>\n      <aside class=\"admonition note\" aria-label=\"Note\">\n        <p>b</p>\n      </aside>\n      <p>tail</p>\n    </div>\n  </li>\n</ul>";

        return [
            'note opened left of the box, after a blank' => [
                "- head\n\n      ::: box\n      a\n     ::: note\n      b\n      :::\n\n  tail\n",
                $open,
            ],
            'without a blank before the tail' => [
                "- head\n\n    ::: box\n    a\n   ::: note\n    b\n    :::\n  tail\n",
                $open,
            ],
            'a second closer closes the box' => [
                "- head\n\n    ::: box\n    a\n   ::: note\n    b\n    :::\n    :::\n\n  tail\n",
                "<ul>\n  <li><p>head</p>\n    <div class=\"box\">\n      <p>a</p>\n      <aside class=\"admonition note\" aria-label=\"Note\">\n        <p>b</p>\n      </aside>\n    </div>\n    <p>tail</p>\n  </li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('provideShapes')]
    public function testCloser(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
