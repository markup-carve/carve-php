<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DataRuleIndexedLookupTest extends TestCase
{
    /**
     * @return array<string, array{string, string, int, int, string}>
     */
    public static function cases(): array
    {
        return [
            'normalized footnote key' => [
                "[^a b]: one\n\n[^a  b]: two",
                'footnote-labels-differ-only-in-whitespace', 13, 25, '/children/1',
            ],
            'next ordered list' => ["i.  x\nb.  y\n", 'ordered-list-kind-split', 0, 5, '/children/0'],
            'table row under same table' => [
                "| a | b |\n| c |", 'table-row-cell-count', 10, 15, '/children/0/children/0/children/1',
            ],
            'nested figure' => [
                "::: figure\n::: figure\n:::\n:::", 'figure-group-nested', 11, 25, '/children/0/children/0',
            ],
        ];
    }

    /**
     * @param string $source
     * @param string $code
     * @param int $start
     * @param int $end
     * @param string $path
     */
    #[DataProvider('cases')]
    public function testIndexedLookup(string $source, string $code, int $start, int $end, string $path): void
    {
        $previous = getenv('CARVE_LINT_RULES');
        putenv('CARVE_LINT_RULES=data');
        try {
            $actual = [];
            foreach (Carve::lint($source, ['rules' => [$code => 'on']]) as $finding) {
                if ($finding->code === $code) {
                    $actual[] = [$finding->startOffset, $finding->endOffset, $finding->path];
                }
            }
            self::assertSame([[$start, $end, $path]], $actual);
        } finally {
            putenv($previous === false ? 'CARVE_LINT_RULES' : 'CARVE_LINT_RULES=' . $previous);
        }
    }
}
