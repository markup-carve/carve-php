<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AContainerOwnedCommentRunStaysTogetherTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'loose description' => [
                ":: t\n: a\n\n  %% c\n  %% d\n\n  b\n",
                ":: t\n: a\n\n  %% c\n  %% d\n\n  b\n",
            ],
            'loose list' => [
                "- a\n\n  %% y\n  %% z\n- c\n",
                "- a\n\n  %% y\n  %% z\n\n- c\n",
            ],
            'tight list' => [
                "- a\n  %% y\n  %% z\n- c\n",
                "- a\n  %% y\n  %% z\n- c\n",
            ],
            'quote' => [
                "> a\n>\n> %% c\n> %% d\n>\n> b\n",
                "> a\n>\n> %% c\n> %% d\n>\n> b\n",
            ],
            'document run' => [
                "a\n\n%% c\n%% d\n\nb\n",
                "a\n\n%% c\n\n%% d\n\nb\n",
            ],
        ];
    }

    #[DataProvider('shapes')]
    public function testFmtSpellsTheRunAndReadsItBack(string $source, string $expected): void
    {
        $formatted = CarveConverter::toCarve($source);

        self::assertSame($expected, $formatted);
        self::assertSame($formatted, CarveConverter::toCarve($formatted));
        self::assertSame((new CarveConverter())->convert($source), (new CarveConverter())->convert($formatted));
    }
}
