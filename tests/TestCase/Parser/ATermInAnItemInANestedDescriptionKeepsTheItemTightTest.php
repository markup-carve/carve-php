<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CARVE-P2-017 ends a folded paragraph before a reached term. A term at an item's
 * content column belongs to that item and ends no paragraph, so the item stays tight.
 */
class ATermInAnItemInANestedDescriptionKeepsTheItemTightTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function sources(): array
    {
        return [
            'depth two' => [":: t\n: a\n  :: t\n  : a\n    - i\n      :: u\n"],
            'depth three' => [":: t\n: a\n  :: t\n  : a\n    :: t\n    : a\n      - i\n        :: u\n"],
            'ordered item' => [":: t\n: a\n  :: t\n  : a\n    1. i\n       :: u\n"],
            'quoted' => ["> :: t\n> : a\n>   :: t\n>   : a\n>     - i\n>       :: u\n"],
        ];
    }

    #[DataProvider('sources')]
    public function testTheItemStaysTight(string $source): void
    {
        $html = (string)preg_replace('/\s+</', '<', (new CarveConverter())->convert($source));

        $this->assertStringContainsString('<li>i<dl><dt>u</dt></dl></li>', $html);
    }
}
