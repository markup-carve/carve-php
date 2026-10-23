<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DjotReferenceDestinationSlashesTest extends TestCase
{
    /**
     * @return array<string, array{int, bool, int}>
     */
    public static function destinations(): array
    {
        return [
            'two slashes in plain link' => [2, false, 0],
            'three slashes in plain link' => [3, false, 0],
            'two slashes in plain image' => [2, true, 0],
            'one slash in link' => [1, false, 1],
            'two slashes in link' => [2, false, 1],
            'three slashes in link' => [3, false, 1],
            'four slashes in link' => [4, false, 1],
            'one slash in image' => [1, true, 1],
            'two slashes in image' => [2, true, 1],
            'two slashes in double backticks' => [2, false, 2],
        ];
    }

    #[DataProvider('destinations')]
    public function testReferenceDestinationKeepsEverySlash(int $slashes, bool $image, int $ticks): void
    {
        $mark = str_repeat('`', $ticks);
        $source = ($image ? 'before ![x][r]' : '[x][r]') . "\n\n[r]: " . $mark . 'a' . str_repeat('\\', $slashes) . 'b' . $mark;
        $carve = (new DjotToCarve())->convert($source);
        $html = (new CarveConverter())->convert($carve);
        $attribute = $image ? 'src' : 'href';

        self::assertStringContainsString($attribute . '="' . $mark . 'a' . str_repeat('\\', $slashes) . 'b' . $mark . '"', $html);
    }

    public function testPlainReferenceDestinationKeepsSlashBeforePunctuation(): void
    {
        $source = "[x][r]\n\n[r]: a\\\\*b";
        $html = (new CarveConverter())->convert((new DjotToCarve())->convert($source));

        self::assertStringContainsString('href="a\\\\*b"', $html);
    }
}
