<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AShiftedTermSeparatorReleasesLaterListMarkersTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function markerLadders(): array
    {
        return [
            'bullet child' => ["- * :: t\n  : a\n   - sibling\n   tail\n"],
            'ordered child' => ["- 1. :: t\n  : a\n   - sibling\n   tail\n"],
            'quote host' => ["> - * :: t\n>   : a\n>    - sibling\n>    tail\n"],
            'list host' => ["- wrap\n  - * :: t\n    : a\n     - sibling\n     tail\n"],
        ];
    }

    #[DataProvider('markerLadders')]
    public function testLaterMarkerOpensInTheSurvivingItem(string $source): void
    {
        $html = CarveConverter::create()->convert($source);

        self::assertStringContainsString("<li>sibling\ntail</li>", $html);
        self::assertStringNotContainsString("\n- sibling\n", $html);
    }
}
