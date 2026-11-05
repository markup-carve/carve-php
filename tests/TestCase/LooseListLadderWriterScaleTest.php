<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Every level of a loose ladder is a one-item loose list, and the writer
 * reparsed each level's whole body to confirm it reads back loose: cubic in
 * depth (carve#5193).
 */
class LooseListLadderWriterScaleTest extends TestCase
{
    use ScalingGuardTrait;

    /**
     * @var int
     */
    private const SMALL = 20;

    /**
     * @var int
     */
    private const LARGE = 80;

    public function testTheLadderIsWrittenUnchanged(): void
    {
        $converter = new CarveConverter();

        $this->assertSame("- x\n\n  - x\n\n    - x\n", $converter->toCarve(self::ladder(3)));
        $this->assertSame(self::ladder(40), $converter->toCarve(self::ladder(40)));
    }

    #[Group('scaling')]
    public function testADeepLooseLadderIsWrittenInTimeLinearInItsSize(): void
    {
        $converter = new CarveConverter();
        $this->assertConversionScalesLinearly(
            static function (string $source) use ($converter): void {
                $converter->toCarve($source);
            },
            self::ladder(self::SMALL),
            self::ladder(self::LARGE),
            'loose list ladder levels',
            self::SMALL,
            self::LARGE,
            maxPerByteRatio: 1.5,
        );
    }

    private static function ladder(int $levels): string
    {
        $lines = [];
        for ($level = 0; $level < $levels; $level++) {
            $lines[] = str_repeat(' ', $level * 2) . '- x';
        }

        return implode("\n\n", $lines) . "\n";
    }
}
