<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Support;

use PHPUnit\Framework\TestCase;

final class LinearityTest extends TestCase
{
    public function testLinearWorkPasses(): void
    {
        // A loop that allocates nothing: `explode()` at 640 KB builds an array past the 2 MB huge-block size and pays
        // allocation the small input does not, which read up to 3.74 on PHP 8.2.
        $linear = static function (int $n): int {
            $sum = 0;
            for ($i = 0; $i < $n; $i++) {
                $sum += $i;
            }

            return $sum;
        };
        $excess = Linearity::excess(static fn () => $linear(60000), static fn () => $linear(480000), 8);
        $this->assertLessThanOrEqual(Linearity::LIMIT, $excess);
    }

    public function testQuadraticWorkFails(): void
    {
        $quadratic = static function (int $n): int {
            $sum = 0;
            for ($i = 0; $i < $n; $i++) {
                for ($j = 0; $j < $n; $j++) {
                    $sum += $i ^ $j;
                }
            }

            return $sum;
        };
        $excess = Linearity::excess(static fn () => $quadratic(150), static fn () => $quadratic(1200), 8);
        $this->assertGreaterThan(Linearity::LIMIT, $excess);
    }

    public function testLinearPlusQuadraticTermFails(): void
    {
        // A quadratic term that is only part of the small input's time still fails at factor 8.
        $mixed = static function (int $n): int {
            $sum = 0;
            for ($i = 0; $i < 150 * $n; $i++) {
                $sum += $i;
            }
            for ($i = 0; $i < $n; $i++) {
                for ($j = 0; $j < $n; $j++) {
                    $sum += $j;
                }
            }

            return $sum;
        };
        $excess = Linearity::excess(static fn () => $mixed(300), static fn () => $mixed(2400), 8);
        $this->assertGreaterThan(Linearity::LIMIT, $excess);
    }
}
