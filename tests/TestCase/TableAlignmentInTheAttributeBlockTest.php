<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Lint\DocumentLinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#5091 (D3: alignment symbols lead the cell's attribute block) and
 * carve#4980 stage 1 (native head rows for span placeholders).
 */
final class TableAlignmentInTheAttributeBlockTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function readings(): array
    {
        return [
            'glued run before ordinary attributes' => ["|{>^ .num} 12.50 |\n", '<td class="num" style="text-align: right; vertical-align: top;">12.50</td>'],
            'a spaced pair is literal' => ["|{> ^} x |\n", '<td>{&gt; ^} x</td>'],
            'a third symbol is literal' => ["|{>^~} x |\n", '<td>{&gt;^~} x</td>'],
            'a lone ? is literal' => ["|{?} x |\n", '<td>{?} x</td>'],
            'an alignment-only placeholder keeps its span' => ["|= a |{<} < |\n| 1 | 2 |\n", '<td style="text-align: left;">2</td>'],
            'an ordinary attribute makes the glyph literal' => ["|{.x} < |\n", '<td class="x">&lt;</td>'],
            'a header-kind placeholder heads its table' => ["|= ^ |\n| x |\n", "<thead>\n    <tr><th scope=\"col\"></th></tr>"],
            'an orphan takes its head row kind' => ["| ^ |= a |\n| 1 | 2 |\n", '<tr><th scope="col"></th><th scope="col">a</th></tr>'],
            'the released empty left header' => ["|=< |\n| x |\n", '<th scope="col" style="text-align: left;"></th>'],
            'a glued =^ is data' => ["|=^ |\n", '<td>=^</td>'],
            'an appended data cell keeps the row in the body' => ["| < |= a |\n+ | | c |\n", '<tr><td></td><th scope="row">a</th><td>c</td></tr>'],
        ];
    }

    #[DataProvider('readings')]
    public function testTheCellReading(string $source, string $fragment): void
    {
        $this->assertStringContainsString($fragment, (new CarveConverter())->convert($source));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function writes(): array
    {
        return [
            'a deprecated prefix moves into braces' => ["|=> Price |\n| 12 |\n", "|={>} Price |\n| 12 |\n"],
            'symbols lead ordinary attributes' => ["|>{.num} 9 |\n", "|{> .num} 9 |\n"],
            'a separator-promoted orphan keeps its kind' => ["| ^ | a |\n|---|---|\n| 1 | 2 |\n", "|= ^ |= a |\n| 1 | 2 |\n"],
            'an all-placeholder head row' => ["| < |\n|---|\n| x |\n", "|= < |\n| x |\n"],
            'a covered default stays on its placeholder' => ["| A | < |\n|---|:--|\n| 1 | 2 |\n", "|= A |={<} < |\n| 1 | 2 |\n"],
            'a literal header glyph is escaped' => ["|= \\< |\n| x |\n", "|= \\< |\n| x |\n"],
            'a literal glyph after an alignment block is escaped' => ["|{?^} \\< |\n", "|{?^} \\< |\n"],
            'an empty aligned body cell keeps its block' => ["|{<} |\n", "|{<} |\n"],
        ];
    }

    #[DataProvider('writes')]
    public function testTheCanonicalWriter(string $source, string $expected): void
    {
        $once = CarveConverter::toCarve($source);
        $this->assertSame($expected, $once);
        $this->assertSame($once, CarveConverter::toCarve($once));
        $this->assertSame((new CarveConverter())->convert($source), (new CarveConverter())->convert($once));
    }

    public function testTheSharedLintVectors(): void
    {
        $data = json_decode((string)file_get_contents(__DIR__ . '/../Fixture/lint-rule-vectors/deprecated-cell-alignment.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($data['vectors'] as $vector) {
            $warnings = array_values(array_filter(
                (new DocumentLinter())->lint($vector['source']),
                static fn ($warning): bool => $warning->rule === $data['rule'],
            ));
            $expected = $vector['expected']['findings'];
            $this->assertSame(
                array_map(static fn (array $finding): array => [$finding['line'], $finding['column']], $expected),
                array_map(static fn ($warning): array => [$warning->line, $warning->column], $warnings),
                $vector['name'],
            );
            foreach ($expected as $index => $finding) {
                foreach ($finding['includes'] as $fragment) {
                    $this->assertStringContainsString($fragment, $warnings[$index]->message, $vector['name']);
                }
            }
        }
    }

    public function testTheFmtFixBytes(): void
    {
        $data = json_decode((string)file_get_contents(__DIR__ . '/../Fixture/lint-rule-vectors/table-alignment-fmt-fix.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($data['vectors'] as $vector) {
            $this->assertSame($vector['fixed'], CarveConverter::toCarve($vector['source']), $vector['name']);
        }
    }
}
