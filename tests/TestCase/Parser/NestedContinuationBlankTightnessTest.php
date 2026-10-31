<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NestedContinuationBlankTightnessTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function sources(): array
    {
        return [
            'stacked marker line' => ["- a\n  - - x\n\n    +\n    y\n"],
            'stacked indented line' => ["- a\n  - x\n    - y\n\n    +\n    z\n"],
        ];
    }

    #[DataProvider('sources')]
    public function testBlankBeforeNestedContinuationDoesNotLoosenAncestor(string $source): void
    {
        $html = (new CarveConverter())->convert($source);

        $this->assertStringStartsWith("<ul>\n  <li>a\n", $html);
        $this->assertStringNotContainsString('<p>a</p>', $html);
    }
}
