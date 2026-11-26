<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Test\Conformance\ImportClauseCasesTest;
use MarkupCarve\Carve\Test\Support\Corpus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-M-041: the `style` of a `<col>` is read as CSS declarations.
 */
final class ColStyleWidthTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function cases(): array
    {
        $out = [];
        foreach (Corpus::clauseCases() as $id => $case) {
            if (isset(ImportClauseCasesTest::KNOWN_FAIL[$id])) {
                continue;
            }
            if (($case['kind'] ?? null) === 'import' && array_intersect(['C2-M-041', 'C2-M-040'], $case['clauses']) !== []) {
                $out[$id] = [$case];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $case
     *
     * @return void
     */
    #[DataProvider('cases')]
    public function testPinnedCase(array $case): void
    {
        $result = Carve::importHtml($case['html'], Corpus::caseOptions($case));
        $this->assertSame($case['expected']['carve'], $result->source);
        $expected = array_map(static fn (array $d): string => $d['code'], $case['expected']['diagnostics']);
        $this->assertSame($expected, $result->codes());
    }

    /**
     * @return array<string, array{string, string|null, bool}>
     */
    public static function styles(): array
    {
        return [
            'tab and form feed around the pieces' => ["\twidth\f:\t25%\t;", '25', false],
            'empty pieces are skipped' => [';; width: 25% ;;', '25', false],
            // carve-js#5574: the last width declaration applies, also where it gives no width.
            'a later width that is out of range gives no width' => ['width: 25%; width: 150%', null, true],
            'a piece without a colon is another declaration' => ['width: 25%; bogus', '25', true],
            'zero gives no width' => ['width: 0%', null, true],
            'the property name compares without case' => ['Width: 25%', '25', false],
            'an empty style gives no width' => ['', null, true],
        ];
    }

    #[DataProvider('styles')]
    public function testDeclarations(string $style, ?string $width, bool $reported): void
    {
        $html = '<table><colgroup><col style="' . htmlspecialchars($style) . '"></colgroup><tr><td>a</td></tr></table>';
        $result = Carve::importHtml($html);
        $this->assertSame(($width === null ? '' : '{widths=' . $width . "}\n") . "| a |\n", $result->source);
        $this->assertSame($reported ? ['import-attribute-dropped'] : [], $result->codes());
    }
}
