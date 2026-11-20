<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DataRulePairsTest extends TestCase
{
    /**
     * @return array<string, array{string, array<array{string, int, int, string}>}>
     */
    public static function cases(): array
    {
        return [
            'strict opener at source start' => ["_em_\n", [['deprecated-bare-underline', 0, 4, '/children/0/children/0']]],
            'strict opener after a word' => ["a_b_\n", []],
            'pair across a soft break' => ["_a\nb_\n", [['deprecated-bare-underline', 0, 5, '/children/0/children/0']]],
            'pair inside a link' => ["a [~sub~](u) z\n", [['deprecated-bare-strike', 3, 8, '/children/0/children/1/children/0']]],
            'double mark' => ["~~x~~\n", [['markdown-strikethrough-double-tilde', 0, 5, '/children/0/children/0']]],
        ];
    }

    /**
     * @param string $source
     * @param array<array{string, int, int, string}> $expected
     */
    #[DataProvider('cases')]
    public function testSharedPairScan(string $source, array $expected): void
    {
        $previous = getenv('CARVE_LINT_RULES');
        putenv('CARVE_LINT_RULES=data');
        try {
            $pairs = [
                'deprecated-bare-underline' => true,
                'deprecated-bare-strike' => true,
                'deprecated-bare-highlight' => true,
                'markdown-strong-double-star' => true,
                'markdown-strikethrough-double-tilde' => true,
                'djot-emphasis-underscore' => true,
                'djot-subscript-tilde' => true,
                'djot-superscript-caret' => true,
            ];
            $actual = [];
            foreach (Carve::lint($source) as $finding) {
                if (isset($pairs[$finding->code])) {
                    $actual[] = [$finding->code, $finding->startOffset, $finding->endOffset, $finding->path];
                }
            }
            self::assertSame($expected, $actual);
        } finally {
            putenv($previous === false ? 'CARVE_LINT_RULES' : 'CARVE_LINT_RULES=' . $previous);
        }
    }
}
