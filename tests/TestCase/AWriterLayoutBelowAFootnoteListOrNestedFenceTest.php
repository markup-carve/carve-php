<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CARVE-P11-079 (c), markup-carve/carve#4850.
 */
class AWriterLayoutBelowAFootnoteListOrNestedFenceTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rows(): array
    {
        return [
            'row 410-4' => ["See[^1].\n\n[^1]: - a\n\n\n\n  - b\n", "See[^1].\n\n[^1]: - a\n\n\n\n      - b\n"],
            'row 616-6' => ["[r][] [^p] [^c]\n[^c]: - para\n   # H\n", "[r][] [^p] [^c]\n\n[^c]: - para\n\n      # H\n"],
            'a longer label' => ["[^long]: - a\n\n  para\n\n[^long]\n", "[^long]\n\n[^long]: - a\n\n         para\n"],
            'a body with no list keeps two columns' => ["[^n]: a\n\n  para\n\n[^n]\n", "[^n]\n\n[^n]: a\n\n  para\n"],
            'row 626-8' => ["- - [ ] ```js\n        x\n\n  +\ntail\n", "- - [ ] ```js\n        x\n\n    ```\n\n  \\+\n  tail\n"],
        ];
    }

    #[DataProvider('rows')]
    public function testTheWriterNamesOneLayout(string $source, string $expected): void
    {
        $written = CarveConverter::toCarve($source);

        $this->assertSame($expected, $written);
        $this->assertSame($written, CarveConverter::toCarve($written));
        $this->assertSame((new CarveConverter())->convert($source), (new CarveConverter())->convert($written));
    }
}
