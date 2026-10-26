<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\TestCase;

final class DjotNestedImageDepthTest extends TestCase
{
    public function testNestedImageLabelsKeepTheirTextAtDepth(): void
    {
        $converter = new DjotToCarve();
        $source = str_repeat('![', 1600) . 'x' . str_repeat('](u)', 1600);
        $written = $converter->convert($source);
        self::assertSame('![x](u)', $written);
        self::assertStringContainsString('<img src="u" alt="x">', (new CarveConverter())->convert($written));
    }

    public function testNestedImageTraversalDoesNotRescanEveryLabel(): void
    {
        $converter = new DjotToCarve();
        $converter->convert('![x](u)');
        $source = str_repeat('![', 400) . 'x' . str_repeat('](u)', 400);
        $start = hrtime(true);
        $converter->convert($source);
        self::assertLessThan(2.0, (hrtime(true) - $start) / 1e9);
    }
}
