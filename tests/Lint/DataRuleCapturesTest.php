<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DataRuleCapturesTest extends TestCase
{
    /**
     * @return array<string, array{string, string, int, int}>
     */
    public static function cases(): array
    {
        return [
            'definition label before indentation' => ["1. x\n   [r]: /u\n", 'definition-in-container', 8, 12],
            'fence closer before body check' => ["```\ncode\n    ```\n", 'fence-closer-indented', 13, 16],
            'optional caret does not carry to next match' => [
                ":: term [ ]\n\n:::: references\n[^ ]: term [ ]\n::::\n",
                'blank-reference-label',
                29,
                33,
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testNamedCapturesKeepTheirScope(string $source, string $code, int $start, int $end): void
    {
        $previous = getenv('CARVE_LINT_RULES');
        putenv('CARVE_LINT_RULES=data');
        try {
            $ranges = [];
            foreach (Carve::lint($source) as $finding) {
                if ($finding->code === $code) {
                    $ranges[] = [$finding->startOffset, $finding->endOffset];
                }
            }
            self::assertSame([[$start, $end]], $ranges);
        } finally {
            putenv($previous === false ? 'CARVE_LINT_RULES' : 'CARVE_LINT_RULES=' . $previous);
        }
    }
}
