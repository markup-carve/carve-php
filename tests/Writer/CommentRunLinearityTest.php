<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Test\Support\Linearity;
use PHPUnit\Framework\TestCase;

final class CommentRunLinearityTest extends TestCase
{
    public function testFormatWalksCommentRunOnce(): void
    {
        $small = str_repeat("%%\n", 200);
        $large = str_repeat("%%\n", 1600);

        $this->assertSame($large, Carve::format($large));
        $excess = Linearity::excess(
            static fn () => Carve::format($small),
            static fn () => Carve::format($large),
            8,
            1,
        );
        $this->assertLessThanOrEqual(Linearity::LIMIT, $excess);
    }
}
