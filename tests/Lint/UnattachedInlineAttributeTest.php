<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Lint\Linter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `unattached-inline-attribute` reports every valid attribute block that attaches to nothing in the text of a
 * paragraph, heading, term or cell (carve#6176). Expected findings are the carve-js output, except the quoted brace,
 * which the row's "valid attribute block" decides.
 */
final class UnattachedInlineAttributeTest extends TestCase
{
    /**
     * @return array<string, array{string, array<array{int, int}>}>
     */
    public static function cases(): array
    {
        return [
            'bare word' => ["a {x} b\n", [[2, 5]]],
            'key and value' => ["a {k=v} b\n", [[2, 7]]],
            'escaped brace' => ["a \\{x} b\n", []],
            'heading' => ["# h {x} y\n", [[4, 7]]],
            'cell' => ["| a {x} b |\n", [[4, 7]]],
            'glued blocks' => ["a {x}{y} b\n", [[2, 5], [5, 8]]],
            'empty block' => ["a {} b\n", []],
            'block of spaces' => ["a { } b\n", []],
            'quoted closing brace' => ["a {k=\"x}y\"} b\n", [[2, 11]]],
        ];
    }

    /**
     * @param string $source
     * @param array<array{int, int}> $expected
     *
     * @return void
     */
    #[DataProvider('cases')]
    public function testFindings(string $source, array $expected): void
    {
        $found = [];
        foreach (Linter::lint($source) as $diagnostic) {
            $data = $diagnostic->toArray();
            if ($data['code'] === 'unattached-inline-attribute') {
                $found[] = [$data['range']['startOffset'], $data['range']['endOffset']];
            }
        }

        $this->assertSame($expected, $found);
    }
}
