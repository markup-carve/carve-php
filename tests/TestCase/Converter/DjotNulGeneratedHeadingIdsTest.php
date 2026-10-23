<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use MarkupCarve\Carve\Converter\HeadingId\MapIds;
use PHPUnit\Framework\TestCase;

class DjotNulGeneratedHeadingIdsTest extends TestCase
{
    public function testDuplicateHeadingIdsSurviveANulElsewhere(): void
    {
        $source = "# A\n\n# A\n\nzero \0 byte\n";
        $carve = (new DjotToCarve())->convert($source);
        $html = (new CarveConverter())->convert($carve);

        $this->assertStringContainsString("{#A-1}\n# A", $carve);
        $this->assertStringContainsString('<section id="A-1">', $html);
        $this->assertSame(1, substr_count($html, 'id="A-1"'));
    }

    public function testNulHeadingReferenceTargetsPinnedDuplicate(): void
    {
        $source = "# a\0\n\n# a�\n\n[a�][]";
        $html = (new CarveConverter())->convert((new DjotToCarve())->convert($source));

        $this->assertStringContainsString('<section id="a�-1">', $html);
        $this->assertStringContainsString('<a href="#a�-1">', $html);
    }

    public function testCustomHeadingIdAlsoUpdatesItsReference(): void
    {
        $source = "# a\0b\n\n[a\0b][]";
        $carve = (new DjotToCarve())->preserveHeadingIds(new MapIds(['published']))->convert($source);
        $html = (new CarveConverter())->convert($carve);

        $this->assertStringContainsString('<section id="published">', $html);
        $this->assertStringContainsString('<a href="#published">', $html);
    }
}
