<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Next2ParityTest extends TestCase
{
    /**
     * @return array<string, array{string, array<array{string, int, int}>}>
     */
    public static function autolinks(): array
    {
        return [
            'denied scheme' => ['<javascript:alert(1)>', []],
            'normal scheme' => ['<https://x.org>', []],
            'real tag after autolink' => ['<javascript:x> <b>t</b>', [['bare-html-tag', 15, 18]]],
        ];
    }

    /**
     * @param string $source
     * @param array<array{string, int, int}> $expected
     */
    #[DataProvider('autolinks')]
    public function testAutolinkSourceIsOpaqueToTagLint(string $source, array $expected): void
    {
        $this->assertSame($expected, self::findings($source));
    }

    /**
     * @return array<string, array{string, array<array{string, int, int}>}>
     */
    public static function cellRuns(): array
    {
        return [
            'header vertical' => ['|=<v Top |', [['deprecated-cell-alignment', 2, 4]]],
            'body vertical' => ['|<v a|', [['deprecated-cell-alignment', 1, 3]]],
            'right vertical' => ['|=>^ a|', [['deprecated-cell-alignment', 2, 4]]],
            // carve#6176: a cell is inline content, so a block glued to no host is also unattached there.
            'glued brace' => [
                '| ~{.x} a |',
                [
                    ['deprecated-cell-alignment', 2, 7],
                    ['emphasis-marker-before-attributes', 2, 7],
                    ['unattached-inline-attribute', 3, 7],
                ],
            ],
            'later attribute' => [
                '| ~{.x} a ~{.y} b |',
                [
                    ['deprecated-cell-alignment', 2, 7],
                    ['emphasis-marker-before-attributes', 2, 7],
                    ['unattached-inline-attribute', 3, 7],
                    ['emphasis-marker-before-attributes', 10, 15],
                    ['unattached-inline-attribute', 11, 15],
                ],
            ],
            'two blocks' => [
                '| ~{.x}{.y} a |',
                [
                    ['emphasis-marker-before-attributes', 2, 7],
                    ['unattached-inline-attribute', 3, 11],
                ],
            ],
            'ordinary cell attribute' => ['| a {.x} b |', [['unattached-inline-attribute', 4, 8]]],
        ];
    }

    /**
     * @param string $source
     * @param array<array{string, int, int}> $expected
     */
    #[DataProvider('cellRuns')]
    public function testWholeLegacyCellRunIsOneFinding(string $source, array $expected): void
    {
        $this->assertSame($expected, self::findings($source));
    }

    /**
     * @return array<array{string, int, int}>
     */
    private static function findings(string $source): array
    {
        return array_map(static fn ($d): array => [$d->code, $d->startOffset, $d->endOffset], Carve::lint($source));
    }
}
