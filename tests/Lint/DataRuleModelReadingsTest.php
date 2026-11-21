<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DataRuleModelReadingsTest extends TestCase
{
    /**
     * @return array<string, array{string, string, list<array{int, int}>}>
     */
    public static function cases(): array
    {
        return [
            // C2-W-140, C2-A-158: a row a continuation joined has no pos; its layout gives the record's span.
            'joined first row' => ["| beta | %% x\n+ beta2 |\n| z | q |\n", 'table-row-cell-count', [[24, 33]]],
            'joined later row' => ["| A | B |\n| x | y | z |\n+|\n", 'table-row-cell-count', [[10, 26]]],
            // C2-B-027: the quote marks before a later line of a code span are no span characters.
            'quoted span line' => ["> >>```=html\n>> >```\n", 'blockquote-marker-without-space', [[13, 20]]],
            // C2-B-109: the run that closed the inner div does not close the admonition around it.
            'closer of a child' => [
                ":::\n:::: warning\n::: box\n::::\n:::\n",
                'colon-fence-length-mismatch',
                [[25, 29], [30, 33]],
            ],
        ];
    }

    /**
     * @param string $source
     * @param string $code
     * @param list<array{int, int}> $expected
     */
    #[DataProvider('cases')]
    public function testDataModelReading(string $source, string $code, array $expected): void
    {
        $previous = getenv('CARVE_LINT_RULES');
        putenv('CARVE_LINT_RULES=data');
        try {
            $found = [];
            foreach (Carve::lint($source) as $finding) {
                if ($finding->code === $code) {
                    $found[] = [$finding->startOffset, $finding->endOffset];
                }
            }
            self::assertSame($expected, $found);
        } finally {
            putenv($previous === false ? 'CARVE_LINT_RULES' : 'CARVE_LINT_RULES=' . $previous);
        }
    }
}
