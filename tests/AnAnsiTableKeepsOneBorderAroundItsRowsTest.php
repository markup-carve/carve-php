<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class AnAnsiTableKeepsOneBorderAroundItsRowsTest extends TestCase
{
    public function testHeaderAndBodyHaveNoExtraBorder(): void
    {
        foreach (
            [
                "|= a | < |= b |\n| 1 | 2 | 3 |\n",
                "> +\n|= a | < |= b |\n",
            ] as $source
        ) {
            $output = CarveConverter::ansi()->convert($source);
            self::assertStringContainsString('┌', $output);
            self::assertStringContainsString('└', $output);
            self::assertStringNotContainsString('├', $output);
        }
    }
}
