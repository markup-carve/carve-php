<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Next2LintRegressionTest extends TestCase
{
    /**
     * @return array<string, array{string, string, array<array{string, int, int}>}>
     */
    public static function cases(): array
    {
        return [
            'caption on a term continuation line' => [
                " :: term\n: | A | B |\n  | x | y |",
                'caption-looking-paragraph',
                [
                    ['caption-looking-paragraph', 9, 10],
                ],
            ],
            'caption on a term continuation line below a tab' => [
                "\t:: t\n: ## H #\n | 1 | 2 |",
                'caption-looking-paragraph',
                [
                    ['caption-looking-paragraph', 6, 7],
                ],
            ],
            'caption not on a nested term marker line' => [
                ":: term\n: :: term\n |",
                'caption-looking-paragraph',
                [],
            ],
            'attribute after paragraph text colon' => [
                ': {.c}',
                'unattached-block-attribute',
                [],
            ],
            'attribute after paragraph text colon in a quote' => [
                '> : {.c}',
                'unattached-block-attribute',
                [],
            ],
            'attribute after a lazy quote mark' => [
                "- [x] done\n> {#id}",
                'unattached-block-attribute',
                [],
            ],
            'attribute on a footnote marker line' => [
                '[^f]: {#id}',
                'unattached-block-attribute',
                [
                    ['unattached-block-attribute', 6, 11],
                ],
            ],
            'attribute at the end of a quote before a div opener' => [
                "> {.c}\n:::",
                'unattached-block-attribute',
                [
                    ['unattached-block-attribute', 2, 6],
                ],
            ],
        ];
    }

    /**
     * @param string $source
     * @param string $rule
     * @param array<array{string, int, int}> $expected
     */
    #[DataProvider('cases')]
    public function testRule(string $source, string $rule, array $expected): void
    {
        $actual = [];
        foreach (Carve::lint($source) as $diagnostic) {
            if ($diagnostic->code === $rule) {
                $actual[] = [$diagnostic->code, $diagnostic->startOffset, $diagnostic->endOffset];
            }
        }
        self::assertSame($expected, $actual);
    }
}
