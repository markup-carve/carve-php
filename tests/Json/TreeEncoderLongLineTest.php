<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Json;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Test\Support\Linearity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A node that starts behind the end of its parent made the encoder count its column from the start of the line again,
 * so the positions of one long line took quadratic time.
 */
final class TreeEncoderLongLineTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function units(): array
    {
        return ['ascii' => [str_repeat('a', 30) . ' *a* '], 'multibyte' => [str_repeat('é', 15) . ' *é* ']];
    }

    #[DataProvider('units')]
    public function testPositionsOfOneLongLineAreLinear(string $unit): void
    {
        $small = str_repeat($unit, 400);
        $large = str_repeat($unit, 3200);
        $smallDoc = Carve::parse($small);
        $largeDoc = Carve::parse($large);
        $excess = Linearity::excess(
            static fn () => (new TreeEncoder())->encode($smallDoc, $small),
            static fn () => (new TreeEncoder())->encode($largeDoc, $large),
            8,
            1,
        );

        $this->assertLessThanOrEqual(Linearity::LIMIT, $excess);
    }

    public function testColumnsCountCodePointsAcrossCheckpoints(): void
    {
        $source = str_repeat('é', 700) . ' *a* ' . str_repeat('漢', 500) . ' `b`';
        /** @var array{children: list<array{children: list<array{pos: array<string, int>}>}>} $tree */
        $tree = (new TreeEncoder())->encode(Carve::parse($source), $source);
        $inline = $tree['children'][0]['children'];
        $strong = $inline[1]['pos'];
        $code = $inline[3]['pos'];

        $this->assertSame([702, 705], [$strong['startColumn'], $strong['endColumn']]);
        $this->assertSame([1207, 1210], [$code['startColumn'], $code['endColumn']]);
    }
}
