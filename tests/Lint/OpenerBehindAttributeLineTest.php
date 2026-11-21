<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Lint\Linter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A block's opener stands on the first line behind its attribute lines, behind that line's spaces and container quotes,
 * as the rule row's opener start reads it (carve-php#5569). Expected findings are the carve-js output, except the last
 * case, where carve-js measures from the attribute line (carve-js#5325).
 */
final class OpenerBehindAttributeLineTest extends TestCase
{
    /**
     * @return array<string, array{string, array<array{string, int, int}>}>
     */
    public static function cases(): array
    {
        return [
            'quote below an indented attribute line' => ["   {#i}\n> a\n", []],
            'nested quote below an indented attribute line' => ["   {#i}\n> > [l](u) {=m=}a\n", []],
            'indented heading marker in a quote' => ["> {.a #b}\n>   # h\n", [['block-opener-overindented', 14, 15]]],
            'indented heading marker' => ["{.a}\n  # h\n", [['block-opener-overindented', 7, 8]]],
            'comment line between the attribute line and an indented heading' => ["{.a}\n%% x\n  # h\n", []],
            'indented continuation of an attributed heading' => [
                "{.a}\n# h\n  more\n",
                [['deprecated-indented-line-under-heading', 9, 15]],
            ],
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
        $found = [];
        foreach (Linter::lint($source) as $diagnostic) {
            $data = $diagnostic->toArray();
            $found[] = [$data['code'], $data['range']['startOffset'], $data['range']['endOffset']];
        }

        $this->assertSame($expected, $found);
    }
}
