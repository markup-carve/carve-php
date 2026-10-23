<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DjotBacktickDestinationSlashesTest extends TestCase
{
    /**
     * @return array<string, array{int, bool, int}>
     */
    public static function slashRuns(): array
    {
        return [
            'one link slash' => [1, false, 1],
            'two link slashes' => [2, false, 1],
            'three link slashes' => [3, false, 1],
            'two image slashes' => [2, true, 1],
            'two slashes in double backticks' => [2, false, 2],
        ];
    }

    #[DataProvider('slashRuns')]
    public function testBacktickDestinationKeepsEverySlash(int $slashes, bool $image, int $ticks): void
    {
        $mark = str_repeat('`', $ticks);
        $djot = ($image ? '![x](' : '[x](') . $mark . 'a' . str_repeat('\\', $slashes) . 'b' . $mark . ')';
        $carve = (new DjotToCarve())->convert($djot);
        $html = (new CarveConverter())->convert($carve);
        $attribute = $image ? 'src' : 'href';
        $expected = $attribute . '="' . str_repeat('%60', $ticks) . 'a' . str_repeat('\\', $slashes) . 'b' . str_repeat('%60', $ticks) . '"';

        self::assertStringContainsString($expected, $html);
    }

    public function testSlashPairsOutsideBackticksKeepDjotEscapeRules(): void
    {
        $carve = (new DjotToCarve())->convert('[x](a\\\\b)');
        $html = (new CarveConverter())->convert($carve);

        self::assertStringContainsString('href="a\\b"', $html);
    }

    public function testBackslashBeforeClosingBacktickIsLiteral(): void
    {
        $carve = (new DjotToCarve())->convert('[x](`a\\\\b\\`)');
        $html = (new CarveConverter())->convert($carve);

        self::assertStringContainsString('href="%60a\\\\b\\%60"', $html);
    }

    public function testEscapedFirstBacktickLeavesAnOpener(): void
    {
        $carve = (new DjotToCarve())->convert('[x](\\``a\\\\b`)');
        $html = (new CarveConverter())->convert($carve);

        self::assertStringContainsString('href="%60%60a\\\\b%60"', $html);
    }
}
