<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Test\Support\Linearity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fuzz find: the writer measured a run of dots (C2-I-114) or hyphens (C2-W-099) in a text node again for each of its
 * characters, so the import of `<p>` with 32 KB of dots took 30 s.
 */
final class TextRunLinearityTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function units(): array
    {
        return ['dots' => ['.'], 'hyphens' => ['-'], 'stars' => ['*'], 'dot pairs' => ['a..']];
    }

    #[DataProvider('units')]
    public function testWritingARunIsLinear(string $unit): void
    {
        $small = '<p>' . str_repeat($unit, 1000) . '</p>';
        $large = '<p>' . str_repeat($unit, 8000) . '</p>';
        $excess = Linearity::excess(static fn () => Carve::importHtml($small), static fn () => Carve::importHtml($large), 8, 1);

        $this->assertLessThanOrEqual(Linearity::LIMIT, $excess);
    }

    public function testRunsKeepTheirText(): void
    {
        foreach (['.', '..', '...', '-', '--', '---', '-----', '*', '***'] as $run) {
            $source = Carve::importHtml('<p>a' . $run . 'b ' . $run . '</p>')->source;
            $this->assertSame('<p>a' . $run . 'b ' . $run . '</p>', trim(Carve::toHtml($source)), $run);
        }
    }
}
