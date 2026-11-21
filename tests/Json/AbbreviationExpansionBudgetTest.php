<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Json;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class AbbreviationExpansionBudgetTest extends TestCase
{
    public function testRepeatedExpansionKeepsTreeSizeLinearInSource(): void
    {
        foreach ([8, 16, 32] as $kilobytes) {
            $uses = $kilobytes * 256;
            $source = '*[A]: ' . str_repeat('x', $uses * 2) . "\n\n" . str_repeat('A ', $uses);
            $json = Carve::toJson($source);
            self::assertLessThanOrEqual(
                1000000 + 100 * strlen($source),
                strlen($json),
                "Tree JSON grew too quickly at {$kilobytes} KB",
            );
        }
    }
}
