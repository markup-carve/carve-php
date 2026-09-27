<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\DefinitionTermFoldLinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A block opener indented under a definition term folds into the term
 * (markup-carve/carve#2411). Rule id, positions and message mirror carve-js.
 */
class DefinitionTermFoldLinterTest extends TestCase
{
    /**
     * @param string $source
     *
     * @return list<array{int, int}>
     */
    protected function reports(string $source): array
    {
        $out = [];
        foreach ((new DefinitionTermFoldLinter())->lint($source) as $warning) {
            if ($warning->rule === DefinitionTermFoldLinter::RULE_DEFINITION_TERM_BLOCK_FOLDED) {
                $out[] = [$warning->line, $warning->column];
            }
        }

        return $out;
    }

    /**
     * @return array<string, array{string, list<array{int, int}>}>
     */
    public static function cases(): array
    {
        return [
            'top level heading' => [":: c\n  # H\n", [[2, 3]]],
            'quoted heading' => ["> :: c\n>   # H\n", [[2, 5]]],
            'heading in a list item' => ["- item\n\n  :: c\n    # H\n", [[4, 5]]],
            'note in a description, once per term' => [":: a\n: b\n  :: c\n    ::: note\n    body\n    :::\n", [[4, 5]]],
            'carriage return' => [":: c\r  # H\r", [[2, 3]]],
            'byte order mark' => ["\u{FEFF}:: c\n # H\n", [[2, 2]]],
            'plain continuation text' => [":: c\n  more text\n", []],
            'list marker' => [":: c\n  - x\n", []],
            'opener at column 0' => [":: c\n# H\n", []],
            'opener at the description column' => [":: a\n: b\n  :: c\n  # H\n", []],
            'inside a code span' => [":: a\n  `code\n  # H\n  end`\n", []],
            'inside a nested code span' => [":: *`code\n  # H\n  end`*\n", []],
            'folded link definition' => ["[t][r]\n\n:: a\n: b\n  :: c\n    [r]: /u\n", [[6, 5]]],
            'folded footnote definition' => ["x[^n]\n\n- item\n\n  :: c\n    [^n]: y\n", [[6, 5]]],
            'inside a folded comment fence' => [":: c\n  %%%\n  # H\n  %%%\n  more\n", []],
            'opener before a trailing comment' => [":: c\n  # H %% note\n", [[2, 3]]],
        ];
    }

    /**
     * @param string $source
     * @param list<array{int, int}> $expected
     */
    #[DataProvider('cases')]
    public function testReportsTheFirstFoldedOpenerPerTerm(string $source, array $expected): void
    {
        $this->assertSame($expected, $this->reports($source));
    }
}
