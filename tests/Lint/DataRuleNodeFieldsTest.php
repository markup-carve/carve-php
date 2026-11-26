<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DataRuleNodeFieldsTest extends TestCase
{
    /**
     * @return array<string, array{string, string, int, int, string}>
     */
    public static function cases(): array
    {
        return [
            'unresolved footnote' => ["a[^x] b\n", 'unresolved-footnote', 1, 5, '/children/0/children/1'],
            'empty figure group' => ["::: figure\ntext\n:::\n", 'figure-group-empty', 0, 19, '/children/0'],
            'single figure panel' => ["::: figure\n| a |\n:::\n", 'figure-group-single-panel', 0, 20, '/children/0'],
            'unused include attributes' => ["{.wide}\n{{ a.crv }}\n", 'include-attributes-unused', 0, 19, '/children/0'],
            'include line hint' => [
                "{table}\n{{ d.csv @lines:1-2 @code:csv }}\n", 'include-lines-table-rows-hint', 17, 27, '/children/0',
            ],
            'heading-like emphasis' => ["*bold*\n", 'emphasis-as-heading', 0, 6, '/children/0'],
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
    public function testNodeFieldsAndCount(string $source, string $code, int $start, int $end, string $path): void
    {
        $previous = getenv('CARVE_LINT_RULES');
        putenv('CARVE_LINT_RULES=data');
        try {
            $found = [];
            foreach (Carve::lint($source, ['rules' => ['emphasis-as-heading' => 'on']]) as $finding) {
                if ($finding->code === $code) {
                    $found[] = [$finding->startOffset, $finding->endOffset, $finding->path];
                }
            }
            self::assertSame([[$start, $end, $path]], $found);
        } finally {
            putenv($previous === false ? 'CARVE_LINT_RULES' : 'CARVE_LINT_RULES=' . $previous);
        }
    }
}
