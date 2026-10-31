<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ATabAfterAMarkerSeparatorIsContentTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function examples(): array
    {
        return [
            'bullet item' => ["- \tx\n  # H\n", "<ul>\n  <li>\tx\n    <h1 id=\"H\">H</h1>\n  </li>\n</ul>\n"],
            'ordered item' => ["1. \tx\n   # H\n", "<ol>\n  <li>\tx\n    <h1 id=\"H\">H</h1>\n  </li>\n</ol>\n"],
            'definition term' => [":: \tx\n", "<dl>\n  <dt>\tx</dt>\n</dl>\n"],
            'description body' => [":: t\n: \tx\n  # H\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>\tx</p>\n    <h1 id=\"H\">H</h1>\n  </dd>\n</dl>\n"],
        ];
    }

    #[DataProvider('examples')]
    public function testTheTabStaysContentAndTheColumnStopsAtTheSpace(string $source, string $expected): void
    {
        $this->assertSame($expected, (new CarveConverter())->convert($source) . "\n");
    }
}
