<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Test\Support\Linearity;
use PHPUnit\Framework\TestCase;

/**
 * The writer copied a paragraph's whole run log once per link of it, so `fmt` of one 512 KB line of links took 28 s instead of 3 s.
 */
final class LinkRunLinearityTest extends TestCase
{
    public function testWritingAParagraphOfLinksIsLinear(): void
    {
        $small = str_repeat('[c [d] e](v) ', 2_500) . "\n";
        $large = str_repeat('[c [d] e](v) ', 40_000) . "\n";
        $excess = Linearity::excess(static fn () => Carve::format($small), static fn () => Carve::format($large), 16, 1);

        $this->assertLessThanOrEqual(Linearity::LIMIT, $excess);
    }
}
