<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DefinitionListSourceColumnsTest extends TestCase
{
    use RuleATodo;

    /**
     * @return array<string, array{string, string}>
     */
    public static function siblingMarkerSources(): array
    {
        return [
            'list item' => ["- a\n  :: outer\n  :  detail\n     :: inner\n      > quote\n   - sibling\n", "<dt>inner\n &gt; quote</dt>\n        </dl>\n      </dd>\n    </dl>\n    <ul>"],
            'nested quote' => ["> - a\n>   :: outer\n>   :  detail\n>      :: inner\n>       > quote\n>    - sibling\n", "<dt>inner\n &gt; quote</dt>\n          </dl>\n        </dd>\n      </dl>\n      <ul>"],
            'shifted term' => ["- a\n    :: outer\n    :  detail\n       :: inner\n        > quote\n   - sibling\n", "<dt>inner\n &gt; quote</dt>\n        </dl>\n      </dd>\n    </dl>\n    <ul>"],
        ];
    }

    #[DataProvider('siblingMarkerSources')]
    public function testSiblingMarkerReturnsToTheSurvivingItem(string $source, string $expected): void
    {
        self::assertStringContainsString($expected, CarveConverter::create()->convert($source));
    }

    public function testTermContinuationDropsTheReleasedItemPrefix(): void
    {
        $html = CarveConverter::create()->convert("- - :: t\n : a\n  :: sibling\n  tail\n");
        self::assertStringContainsString("<dt>sibling\ntail</dt>", $html);
    }
}
