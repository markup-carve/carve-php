<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Lint\Linter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Djot pair rules take pairs left to right, each opener with the next closer, as their rule rows do
 * (carve-php#5570). Expected findings are the carve-js output.
 */
final class DjotPairRulesTest extends TestCase
{
    /**
     * @return array<string, array{string, array<array{string, int, int}>}>
     */
    public static function cases(): array
    {
        return [
            'double-tilde run' => ["~~a~~\n", [['markdown-strikethrough-double-tilde', 0, 5]]],
            'pair at the start of a run' => ["{~s~}~a~x~\n", [['djot-subscript-tilde', 5, 8]]],
            'subscript between letters' => ["H~2~O\n", [['djot-subscript-tilde', 1, 4]]],
            'superscript at the start of a later line' => [
                "^y^\n_e\n^y^\n",
                [['djot-superscript-caret', 0, 3], ['djot-superscript-caret', 7, 10]],
            ],
            'underscores inside a word' => ["snake_case_name\n", []],
            'caret run before a letter' => ["^^b^\n", []],
            'tilde run behind a letter' => ["b~~~\n", []],
            'pairs taken left to right' => ["b^\nx^2^b~b^\n", [['djot-superscript-caret', 6, 11]]],
            'underscore pair behind a run' => ["__f__ef_\n", [['djot-emphasis-underscore', 4, 8]]],
        ];
    }

    /**
     * @param string $source
     * @param array<array{string, int, int}> $expected
     *
     * @return void
     */
    #[DataProvider('cases')]
    public function testFindings(string $source, array $expected): void
    {
        $rules = ['djot-emphasis-underscore' => 'on', 'djot-subscript-tilde' => 'on', 'djot-superscript-caret' => 'on'];
        $found = [];
        foreach (Linter::lint($source, ['rules' => $rules]) as $diagnostic) {
            $data = $diagnostic->toArray();
            $found[] = [$data['code'], $data['range']['startOffset'], $data['range']['endOffset']];
        }

        $this->assertSame($expected, $found);
    }
}
