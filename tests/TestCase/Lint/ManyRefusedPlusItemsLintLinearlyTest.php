<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\DocumentLinter;
use MarkupCarve\Carve\Lint\ListColumnLinter;
use MarkupCarve\Carve\Test\TestCase\ScalingGuardTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The list column checks looked at every item for every item and marker row (carve-php#5169).
 */
final class ManyRefusedPlusItemsLintLinearlyTest extends TestCase
{
    use ScalingGuardTrait;

    /**
     * @var int
     */
    private const SMALL = 1000;

    /**
     * @var int
     */
    private const LARGE = 4000;

    public function testEachRefusedPlusItemGetsOneWarning(): void
    {
        $warnings = (new DocumentLinter())->lint(str_repeat("- +\n\n", 3));

        self::assertSame(
            [[1, 3], [3, 3], [5, 3]],
            array_map(static fn ($warning): array => [$warning->line, $warning->column], $warnings),
        );
        self::assertSame(['continuation-marker-refused'], array_values(array_unique(array_map(static fn ($warning): string => $warning->rule, $warnings))));
    }

    /**
     * The parse dominates at 1k items, so this shape needs twice the size to show the item scans.
     */
    #[Group('scaling')]
    public function testManyRefusedPlusItemsLintLinearly(): void
    {
        $linter = new ListColumnLinter();
        $this->assertConversionScalesLinearly(
            static function (string $source) use ($linter): void {
                $linter->lint($source);
            },
            str_repeat("- +\n\n", 2 * self::SMALL),
            str_repeat("- +\n\n", 2 * self::LARGE),
            'refused plus items',
            2 * self::SMALL,
            2 * self::LARGE,
        );
    }

    #[Group('scaling')]
    public function testManyRaggedSiblingItemsLintLinearly(): void
    {
        $linter = new ListColumnLinter();
        $this->assertConversionScalesLinearly(
            static function (string $source) use ($linter): void {
                $linter->lint($source);
            },
            str_repeat("- a\n\n - b\n\n", self::SMALL),
            str_repeat("- a\n\n - b\n\n", self::LARGE),
            'ragged sibling items',
            self::SMALL,
            self::LARGE,
        );
    }
}
