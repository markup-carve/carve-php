<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Lpfix4ParityTest extends TestCase
{
    /**
     * @return array<string, array{string, string, array<array{string, int, int}>}>
     */
    public static function cases(): array
    {
        return [
            'task item div in a footnote' => [
                "[^f]: - [ ] ::: box\n\t- x\n", 'list-item-block-overindented',
                [['list-item-block-overindented', 12, 19]],
            ],
            'plain item div begins at its content column' => [
                "- ::: box\ninside\n", 'list-item-block-overindented', [],
            ],
            'blank label on a lazy paragraph line' => [
                "[^f]: > a\n> [ ]: u\n", 'blank-reference-label', [],
            ],
            'blank definition label on the first paragraph line' => [
                "[ ]: u\n", 'blank-reference-label',
                [['blank-reference-label', 0, 4]],
            ],
            'inline blank label on a later paragraph line' => [
                "a\nb [x][ ]\n", 'blank-reference-label',
                [['blank-reference-label', 7, 10]],
            ],
        ];
    }

    /**
     * @param string $source
     * @param string $rule
     * @param array<array{string, int, int}> $expected
     */
    #[DataProvider('cases')]
    public function testLintParity(string $source, string $rule, array $expected): void
    {
        $actual = array_values(array_map(
            static fn ($d): array => [$d->code, $d->startOffset, $d->endOffset],
            array_filter(Carve::lint($source), static fn ($d): bool => $d->code === $rule),
        ));
        self::assertSame($expected, $actual);
    }
}
