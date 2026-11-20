<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class BracedUnderlineItemTest extends TestCase
{
    public function testBracedUnderlineIsNotAnAttributeWarning(): void
    {
        foreach (["- {_u_} x\n", "{_u_} x\n", "> {_u_} x\n"] as $source) {
            self::assertSame([], Carve::lint($source), $source);
        }
    }
}
