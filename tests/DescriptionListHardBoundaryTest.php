<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DescriptionListHardBoundaryTest extends TestCase
{
    /**
     * @return array<string, array{int, int}>
     */
    public static function boundaries(): array
    {
        return [
            'no blank' => [0, 1],
            'one blank' => [1, 1],
            'two blanks' => [2, 1],
            'three blanks' => [3, 2],
            'longer run' => [5, 2],
        ];
    }

    #[DataProvider('boundaries')]
    public function testOnlyThreeBlanksSeparateTwoDescriptionLists(int $blanks, int $lists): void
    {
        $source = ":: t\n: x\n" . str_repeat("\n", $blanks) . ":: u\n: v\n";
        $converter = CarveConverter::create();
        $html = $converter->convert($source);

        self::assertSame($lists, substr_count($html, '<dl>'));
        if ($lists === 2) {
            $formatted = $converter->toCarve($source);
            self::assertSame(":: t\n: x\n\n\n\n:: u\n: v\n", $formatted);
            self::assertSame($html, $converter->convert($formatted));
            self::assertSame($formatted, $converter->toCarve($formatted));
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function itemHosts(): array
    {
        return [
            'bullet' => ["- lead\n  :: t\n  : a\n\n\n\n  :: u\n  : b\n"],
            'ordered column shift' => ["10. lead\n    :: t\n    : a\n\n\n\n    :: u\n    : b\n"],
        ];
    }

    #[DataProvider('itemHosts')]
    public function testTheBoundaryAlsoSeparatesListsInsideAnItem(string $source): void
    {
        $converter = CarveConverter::create();
        $html = $converter->convert($source);
        $formatted = $converter->toCarve($source);

        self::assertSame(2, substr_count($html, '<dl>'));
        self::assertSame($html, $converter->convert($formatted));
        self::assertSame($formatted, $converter->toCarve($formatted));
    }
}
