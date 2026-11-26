<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Shared lint cases that data mode answered differently from the handwritten rules and from carve-js data mode.
 */
final class DataLintParityTest extends TestCase
{
    /**
     * @return array<string, array{string, string, array<string>}>
     */
    public static function cases(): array
    {
        $out = [];
        $cases = [
            'attribute block after the last cell is not a cell' => ["| a |{.x}|\n", 'table-row-attribute-as-cell', []],
            'tag after a long run of comments' => [str_repeat('<!---->', 5000) . "<i>\n", 'bare-html-tag', ['/children/0']],
            'tag in a table cell names the cell' => ["| <i> |\n", 'bare-html-tag', ['/children/0/children/0/children/0']],
            'one-item roman list before a letter list' => ["i) x\na) y\n", 'ordered-list-kind-split', ['/children/0']],
        ];
        foreach (['hand', 'data'] as $mode) {
            foreach ($cases as $name => [$source, $rule, $paths]) {
                $out[$mode . ': ' . $name] = [$mode, $source, $rule, $paths];
            }
        }

        return $out;
    }

    /**
     * @param string $mode
     * @param string $source
     * @param string $rule
     * @param array<string> $paths
     *
     * @return void
     */
    #[DataProvider('cases')]
    public function testFindings(string $mode, string $source, string $rule, array $paths): void
    {
        $previous = getenv('CARVE_LINT_RULES');
        putenv($mode === 'data' ? 'CARVE_LINT_RULES=data' : 'CARVE_LINT_RULES');
        try {
            $found = [];
            foreach (Carve::lint($source) as $finding) {
                if ($finding->code === $rule) {
                    $found[] = $finding->path;
                }
            }
            $this->assertSame($paths, $found);
        } finally {
            putenv($previous === false ? 'CARVE_LINT_RULES' : 'CARVE_LINT_RULES=' . $previous);
        }
    }
}
