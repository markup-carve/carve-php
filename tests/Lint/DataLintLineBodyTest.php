<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5839: a line's body starts after its container prefixes, not at the leaf that starts on it, as carve-js
 * and carve-rs read the lint line model.
 */
final class DataLintLineBodyTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, array<string>}>
     */
    public static function cases(): array
    {
        $out = [];
        $cases = [
            'indented task item with a comment fence below a definition' => [
                "[a]: <>\n    - [ ] %%%\n      :::\n",
                'link-definition-indented-line',
                ['12-21 /children/0'],
            ],
        ];
        foreach (['hand', 'data'] as $mode) {
            foreach ($cases as $name => [$source, $rule, $expected]) {
                $out[$mode . ': ' . $name] = [$mode, $source, $rule, $expected];
            }
        }

        return $out;
    }

    /**
     * @param string $mode
     * @param string $source
     * @param string $rule
     * @param array<string> $expected
     *
     * @return void
     */
    #[DataProvider('cases')]
    public function testFinding(string $mode, string $source, string $rule, array $expected): void
    {
        $previous = getenv('CARVE_LINT_RULES');
        putenv($mode === 'data' ? 'CARVE_LINT_RULES=data' : 'CARVE_LINT_RULES');
        try {
            $found = [];
            foreach (Carve::lint($source) as $finding) {
                if ($finding->code === $rule) {
                    $found[] = $finding->startOffset . '-' . $finding->endOffset . ' ' . $finding->path;
                }
            }
            $this->assertSame($expected, $found);
        } finally {
            putenv($previous === false ? 'CARVE_LINT_RULES' : 'CARVE_LINT_RULES=' . $previous);
        }
    }
}
