<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The maintainer rulings of carve#6174, #6175, #6176 and #6178 (19 November).
 */
final class LintRulingsTest extends TestCase
{
    /**
     * @return array<string, array{string, string, array<array{int, int}>}>
     */
    public static function cases(): array
    {
        return [
            '6174 task item' => ['- [ ] ___', 'thematic-break-spelling', [[6, 9]]],
            '6174 bullet' => ['* ----', 'thematic-break-spelling', [[2, 6]]],
            '6174 footnote' => ['[^f]: ---', 'thematic-break-spelling', [[6, 9]]],
            '6174 description' => [":: term\n: ----", 'thematic-break-spelling', [[10, 14]]],
            '6174 lazy quote mark is text' => [":: t\n> ---", 'thematic-break-spelling', []],
            '6174 ordered spaced' => ['a. - - -', 'deprecated-spaced-thematic-break', [[3, 8]]],
            '6174 spaced run keeps its markers' => ['- - -', 'deprecated-spaced-thematic-break', [[0, 5]]],
            '6174 spaced run in a quote' => ['>       - - -', 'deprecated-spaced-thematic-break', [[8, 13]]],
            '6174 ordered empty definition' => ['2) [a]:', 'empty-reference-definition', [[3, 7]]],
            '6174 description empty definition' => [":: term\n: [a]:", 'empty-reference-definition', [[10, 14]]],
            '6175 directive' => [":::: references\n:::", 'colon-fence-length-mismatch', [[16, 19]]],
            '6175 run closes the directive' => ["::::\n::: glossary\n:::\n::::", 'colon-fence-length-mismatch', []],
            '6175 run opens an anonymous div' => ["::::\n:::", 'colon-fence-length-mismatch', [[5, 8]]],
            '6176 cell' => ['| x {#id} y |', 'unattached-inline-attribute', [[4, 9]]],
            '6176 cell start' => ['| a | {#id} |', 'unattached-inline-attribute', []],
            '6178 quote left of the item' => [
                '    i) > $$x$$' . "\n" . '   > ## H',
                'block-opener-without-blank-line',
                [[18, 19]],
            ],
            '6178 lazy quote mark' => ["[^f]: > [^m]: key [ref][]\n> [", 'block-opener-without-blank-line', [[26, 27]]],
            '6178 lazy line below a quote line' => [
                "10. %%#region\n     > :\n> [ ]: u",
                'block-opener-without-blank-line',
                [[23, 24]],
            ],
            '6178 own quote mark' => ["- > a\n  > ## H", 'block-opener-without-blank-line', [[10, 12]]],
        ];
    }

    /**
     * @param string $source
     * @param string $code
     * @param array<array{int, int}> $expected
     */
    #[DataProvider('cases')]
    public function testRuling(string $source, string $code, array $expected): void
    {
        $found = [];
        foreach (Carve::lint($source, ['upgrade' => true]) as $d) {
            if ($d->code === $code) {
                $found[] = [$d->startOffset, $d->endOffset];
            }
        }
        $this->assertSame($expected, $found);
    }
}
