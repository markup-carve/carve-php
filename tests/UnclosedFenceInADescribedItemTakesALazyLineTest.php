<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A line left of the item's column ends the item and its unclosed fence, so the
 * paragraph it opens takes a later lazy line (carve#4727).
 */
final class UnclosedFenceInADescribedItemTakesALazyLineTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function sources(): iterable
    {
        foreach (['~~~', '```'] as $fence) {
            yield "description-$fence" => [":: t\n: - $fence\n  x\ntail\n"];
            yield "depth-2-$fence" => [":: t\n: - - $fence\n    x\ntail\n"];
            yield "ordered-$fence" => [":: t\n: 1. $fence\n  x\ntail\n"];
            yield "in-an-item-$fence" => ["- :: t\n  : - $fence\n    x\ntail\n"];
        }
    }

    #[DataProvider('sources')]
    public function testTheLazyLineJoinsTheParagraph(string $source): void
    {
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString("x\ntail", $html, $source);
        self::assertStringContainsString('<pre><code></code></pre>', $html, $source);
    }
}
